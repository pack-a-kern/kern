import assert from "node:assert/strict";
import { createServer, request as httpRequest, type Server } from "node:http";
import { connect, createServer as createTcpServer, type AddressInfo, type Socket } from "node:net";
import { describe, it } from "node:test";

import { isPrivateAddress, startEgressProxy } from "../src/network/egressProxy.js";
import { PlaybookInvalidError, validatePlaybook } from "../src/playbook/validate.js";
import { createPlaywrightBrowserContext } from "../src/sessions/browserContextFactory.js";

/**
 * The browser's only way out, against a real Chromium and a real server: what the playbook allows
 * goes through, and nothing else does — a redirect included, which no step of a playbook sees.
 */
async function withSite(fn: (base: string, hits: string[]) => Promise<void>): Promise<void> {
  const hits: string[] = [];
  const site: Server = createServer((req, res) => {
    hits.push(`${req.headers.host ?? ""}${req.url ?? ""}`);

    if (req.url === "/away") {
      // To the same server under a name the playbook does not allow.
      res.writeHead(302, { location: `http://localhost:${String((site.address() as AddressInfo).port)}/secret` }).end();

      return;
    }

    res.writeHead(200, { "content-type": "text/html" }).end("<p>page</p>");
  });

  await new Promise<void>((resolve) => site.listen(0, "127.0.0.1", resolve));

  try {
    await fn(`http://127.0.0.1:${String((site.address() as AddressInfo).port)}`, hits);
  } finally {
    await new Promise<void>((resolve) => site.close(() => resolve()));
  }
}

describe("the egress proxy", () => {
  it("lets the browser reach what the playbook allows", async () => {
    await withSite(async (base) => {
      const handle = await createPlaywrightBrowserContext(["127.0.0.1"]);

      try {
        const page = await handle.context.newPage();
        const response = await page.goto(`${base}/act`);

        assert.equal(response?.status(), 200);
        assert.match(await page.content(), /page/);
      } finally {
        await handle.close();
      }
    });
  });

  it("refuses a redirect to a host the playbook does not allow, before it is asked", async () => {
    await withSite(async (base, hits) => {
      const handle = await createPlaywrightBrowserContext(["127.0.0.1"]);

      try {
        const page = await handle.context.newPage();

        await assert.rejects(page.goto(`${base}/away`));
        assert.deepEqual(
          hits.filter((hit) => hit.endsWith("/secret")),
          [],
        );
      } finally {
        await handle.close();
      }
    });
  });

  it("refuses a name that resolves inside a private network, even when the playbook allows the name", async () => {
    await withSite(async (base, hits) => {
      const port = new URL(base).port;
      const handle = await createPlaywrightBrowserContext(["localhost"]);

      try {
        const page = await handle.context.newPage();

        await assert.rejects(page.goto(`http://localhost:${port}/act`));
        assert.deepEqual(hits, []);
      } finally {
        await handle.close();
      }
    });
  });

  it("knows the private ranges, IPv4 written as IPv6 included", () => {
    for (const address of ["10.1.2.3", "127.0.0.1", "169.254.169.254", "172.20.0.1", "192.168.1.1", "100.64.0.1", "::1", "fd00::1", "fe80::1", "::ffff:10.0.0.1"]) {
      assert.equal(isPrivateAddress(address), true, address);
    }

    for (const address of ["85.120.166.86", "1.1.1.1", "2606:4700:4700::1111"]) {
      assert.equal(isPrivateAddress(address), false, address);
    }
  });

  it("sends what it lets through on through the playbook's own proxy, with its credentials", async () => {
    await withUpstream(async (upstream, seen) => {
      await withSite(async (base) => {
        const handle = await createPlaywrightBrowserContext(["127.0.0.1"], { proxy: { server: upstream, username: "u", password: "p" } });

        try {
          const page = await handle.context.newPage();
          const response = await page.goto(`${base}/act`);

          assert.equal(response?.status(), 200);
          assert.deepEqual(seen, [{ request: `GET ${base}/act`, authorization: `Basic ${Buffer.from("u:p").toString("base64")}` }]);
        } finally {
          await handle.close();
        }
      });
    });
  });

  it("never hands the playbook's proxy a request the allow-list refuses", async () => {
    await withUpstream(async (upstream, seen) => {
      await withSite(async (base) => {
        const handle = await createPlaywrightBrowserContext(["127.0.0.1"], { proxy: { server: upstream } });

        try {
          const page = await handle.context.newPage();

          await assert.rejects(page.goto(`http://localhost:${new URL(base).port}/secret`));
          assert.deepEqual(seen, []);
        } finally {
          await handle.close();
        }
      });
    });
  });

  it("opens an https tunnel through the playbook's proxy, to the host it was asked for", async () => {
    const echo = createTcpServer((socket) => socket.pipe(socket));
    await new Promise<void>((resolve) => echo.listen(0, "127.0.0.1", resolve));
    const echoPort = (echo.address() as AddressInfo).port;

    await withUpstream(async (upstream, seen) => {
      const egress = await startEgressProxy(["127.0.0.1"], { server: upstream });
      const client: Socket = connect(Number(new URL(egress.url).port), "127.0.0.1");

      try {
        const reply = await new Promise<string>((resolve) => {
          let received = "";

          client.on("data", (chunk: Buffer) => {
            received += chunk.toString();

            if (received.includes("\r\n\r\n") && !received.includes("ping")) {
              client.write("ping");
            }

            if (received.endsWith("ping")) {
              resolve(received);
            }
          });
          client.write(`CONNECT 127.0.0.1:${String(echoPort)} HTTP/1.1\r\nHost: 127.0.0.1\r\n\r\n`);
        });

        assert.match(reply, /^HTTP\/1\.1 200/);
        assert.deepEqual(seen, [{ request: `CONNECT 127.0.0.1:${String(echoPort)}`, authorization: undefined }]);
      } finally {
        client.destroy();
        await egress.close();
        await new Promise<void>((resolve) => echo.close(() => resolve()));
      }
    });
  });

  it("refuses a playbook proxy that is not plain http://host:port", () => {
    for (const server of ["https://proxy.example:8443", "socks5://proxy.example:1080", "http://user:pass@proxy.example:8000", "not a url"]) {
      assert.throws(
        () => validatePlaybook({ version: 1, name: "p", allow: ["example.test"], context: { proxy: { server } }, sequences: { s: [{ goto: "https://example.test" }] } }),
        PlaybookInvalidError,
        server,
      );
    }
  });
});

/**
 * A proxy standing in for a playbook's own — a mobile one, say — that records what it was asked and
 * forwards it: a plain request to its address, a CONNECT as a tunnel.
 */
async function withUpstream(
  fn: (url: string, seen: { request: string; authorization: string | undefined }[]) => Promise<void>,
): Promise<void> {
  const seen: { request: string; authorization: string | undefined }[] = [];
  const proxy: Server = createServer((req, res) => {
    seen.push({ request: `${req.method ?? ""} ${req.url ?? ""}`, authorization: req.headers["proxy-authorization"] });
    const target = new URL(req.url ?? "");
    const forwarded = httpRequest({ host: target.hostname, port: target.port, path: `${target.pathname}${target.search}`, method: req.method, headers: req.headers }, (answer) => {
      res.writeHead(answer.statusCode ?? 502, answer.headers);
      answer.pipe(res);
    });

    req.pipe(forwarded);
  });

  proxy.on("connect", (req, client: Socket) => {
    seen.push({ request: `CONNECT ${req.url ?? ""}`, authorization: req.headers["proxy-authorization"] });
    const [host, port] = (req.url ?? "").split(":");
    const target = connect(Number(port), host, () => {
      client.write("HTTP/1.1 200 Connection Established\r\n\r\n");
      target.pipe(client);
      client.pipe(target);
    });
  });

  await new Promise<void>((resolve) => proxy.listen(0, "127.0.0.1", resolve));

  try {
    await fn(`http://127.0.0.1:${String((proxy.address() as AddressInfo).port)}`, seen);
  } finally {
    proxy.closeAllConnections();
    await new Promise<void>((resolve) => proxy.close(() => resolve()));
  }
}
