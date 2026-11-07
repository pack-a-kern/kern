import type { LookupAddress } from "node:dns";
import { lookup as dnsLookup } from "node:dns/promises";
import { createServer, request as httpRequest, type IncomingMessage, type ServerResponse } from "node:http";
import { BlockList, connect, isIP, type Socket } from "node:net";
import type { AddressInfo } from "node:net";

import { logger } from "../logger.js";
import { isAllowed } from "../playbook/allowList.js";
import type { AllowList, UpstreamProxy } from "../playbook/types.js";

/**
 * The only way out of a browser this worker opens: an HTTP proxy on loopback, one per browser, that
 * lets through what the browser's playbook allows and nothing else.
 *
 * ## Why a proxy, and not the interpreter's checks
 *
 * The interpreter checks the address a `goto` names and the page it lands on — after the fact. Chromium
 * follows a redirect on its own, fetches a page's scripts, frames and images, and runs what they ask
 * for, and none of that passes through a step. Playwright's request routing does not see a redirect's
 * next hop either (tried: a route saw `/start`, and the server still received the request for the
 * address it redirected to). Every one of those requests does pass through the browser's proxy, so the
 * proxy is where the allow-list can hold for all of them.
 *
 * ## What it refuses
 *
 * - A host the playbook's allow-list does not name.
 * - A host that resolves to an address inside a private network — loopback, RFC 1918, link-local and
 *   the cloud metadata address, carrier-grade NAT, unique-local IPv6 — so a site allowed by name cannot
 *   send the browser into this server's own network or the machines beside it, by a redirect or by its
 *   DNS. The address checked is the address connected to: a name that resolves differently a second
 *   time is not looked up a second time.
 *
 * One exception, written where a reader can see it: an IP address the allow-list names exactly is
 * reached whatever range it is in. That is how a test points the worker at a server on 127.0.0.1, and
 * "*" does not count as naming one.
 *
 * ## Through the playbook's own proxy
 *
 * A playbook may name a proxy to go out through — a mobile or residential one, so a site sees that
 * network rather than this server's (`context.proxy`). It is chained behind this one, never in its
 * place: the browser still talks only to this proxy, every request is still checked here, and only
 * what passes is sent on through the playbook's. Chromium given that proxy directly would go round
 * every check above. The upstream proxy's own address is the playbook's to choose, a private one
 * included — it is where the playbook's author decided the traffic leaves. A host is checked against
 * the private ranges as this side resolves it; the upstream proxy resolves it again for itself.
 */
export interface EgressProxy {
  /** `http://127.0.0.1:<port>` — what the browser is launched with. */
  readonly url: string;
  close(): Promise<void>;
}

type Lookup = (host: string) => Promise<LookupAddress>;

const PRIVATE = new BlockList();

for (const [network, prefix] of [
  ["0.0.0.0", 8],
  ["10.0.0.0", 8],
  ["100.64.0.0", 10],
  ["127.0.0.0", 8],
  ["169.254.0.0", 16],
  ["172.16.0.0", 12],
  ["192.0.0.0", 24],
  ["192.168.0.0", 16],
  ["198.18.0.0", 15],
  ["224.0.0.0", 4],
  ["240.0.0.0", 4],
] as const) {
  PRIVATE.addSubnet(network, prefix, "ipv4");
}

for (const [network, prefix] of [
  ["::", 128],
  ["::1", 128],
  ["fc00::", 7],
  ["fe80::", 10],
  ["ff00::", 8],
] as const) {
  PRIVATE.addSubnet(network, prefix, "ipv6");
}

/** Whether `address` is inside a network this worker's browsers may not reach. */
export function isPrivateAddress(address: string): boolean {
  // An IPv4 address written as IPv6 — `::ffff:10.0.0.1` — is the IPv4 address.
  const mapped = /^::ffff:(\d+\.\d+\.\d+\.\d+)$/i.exec(address)?.[1];

  if (mapped !== undefined) {
    return PRIVATE.check(mapped, "ipv4");
  }

  return isIP(address) === 6 ? PRIVATE.check(address, "ipv6") : PRIVATE.check(address, "ipv4");
}

/** Where a playbook's proxy listens, and what to send it to be let through. */
interface Upstream {
  readonly host: string;
  readonly port: number;
  readonly authorization: string | undefined;
}

/** The most an upstream proxy's answer to CONNECT may be before its tunnel opens — a header, not a page. */
const MAX_TUNNEL_ANSWER_BYTES = 16 * 1024;

/** How long an upstream proxy has to open a tunnel. */
const TUNNEL_TIMEOUT_MS = 30_000;

function upstreamFrom(proxy: UpstreamProxy | undefined): Upstream | undefined {
  if (proxy === undefined) {
    return undefined;
  }

  const server = new URL(proxy.server);
  const credentials = proxy.username === undefined ? undefined : `${proxy.username}:${proxy.password ?? ""}`;

  return {
    host: server.hostname.replace(/^\[(.*)\]$/, "$1"),
    port: server.port === "" ? 80 : Number(server.port),
    authorization: credentials === undefined ? undefined : `Basic ${Buffer.from(credentials).toString("base64")}`,
  };
}

/**
 * A tunnel to `authority` through the upstream proxy — the socket, and whatever arrived after its answer —
 * or null when it would not open one.
 */
async function tunnelThrough(upstream: Upstream, authority: string): Promise<{ socket: Socket; rest: Buffer } | null> {
  return await new Promise((resolve) => {
    const socket = connect(upstream.port, upstream.host);
    let answer = Buffer.alloc(0);

    const fail = (): void => {
      socket.destroy();
      resolve(null);
    };

    socket.setTimeout(TUNNEL_TIMEOUT_MS, fail);
    socket.on("error", fail);
    socket.on("connect", () => {
      const auth = upstream.authorization === undefined ? "" : `Proxy-Authorization: ${upstream.authorization}\r\n`;

      socket.write(`CONNECT ${authority} HTTP/1.1\r\nHost: ${authority}\r\n${auth}\r\n`);
    });

    const onData = (chunk: Buffer): void => {
      answer = Buffer.concat([answer, chunk]);
      const end = answer.indexOf("\r\n\r\n");

      if (end === -1) {
        if (answer.length > MAX_TUNNEL_ANSWER_BYTES) {
          fail();
        }

        return;
      }

      socket.off("data", onData);
      socket.setTimeout(0);

      if (!/^HTTP\/1\.[01] 200\b/.test(answer.subarray(0, end).toString("latin1"))) {
        fail();

        return;
      }

      resolve({ socket, rest: answer.subarray(end + 4) });
    };

    socket.on("data", onData);
  });
}

export async function startEgressProxy(
  allow: AllowList,
  proxy?: UpstreamProxy,
  lookup: Lookup = async (host) => dnsLookup(host, { verbatim: true }),
): Promise<EgressProxy> {
  const sockets = new Set<Socket>();
  const upstreamProxy = upstreamFrom(proxy);

  /** The address to connect to for `host`, or null when it may not be reached. */
  const destination = async (host: string): Promise<string | null> => {
    const bare = host.replace(/^\[(.*)\]$/, "$1").toLowerCase();

    if (!isAllowed(`https://${isIP(bare) === 6 ? `[${bare}]` : bare}/`, allow)) {
      return null;
    }

    const named = isIP(bare) !== 0 && allow.some((entry) => entry.toLowerCase() === bare);

    let address: string;

    try {
      address = isIP(bare) !== 0 ? bare : (await lookup(bare)).address;
    } catch {
      return null;
    }

    return named || !isPrivateAddress(address) ? address : null;
  };

  const refuse = (host: string): void => {
    // The host only: a path or a query can carry what the run was asked to keep to itself.
    logger.warn("egress_refused", { event: "egress_refused", host });
  };

  const server = createServer((req: IncomingMessage, res: ServerResponse): void => {
    void (async (): Promise<void> => {
      let target: URL;

      try {
        target = new URL(req.url ?? "");
      } catch {
        res.writeHead(400).end();

        return;
      }

      if (target.protocol !== "http:") {
        res.writeHead(400).end();

        return;
      }

      const address = await destination(target.hostname);

      if (address === null) {
        // Dropped, not answered: a 403 here would be a page to the browser — one a `source` step could
        // keep as if the site had served it. A dropped connection is a navigation that failed.
        refuse(target.hostname);
        req.socket.destroy();

        return;
      }

      const headers = { ...req.headers };
      delete headers["proxy-connection"];
      delete headers["proxy-authorization"];

      // To the site, or — when the playbook names one — to its proxy, asked for the whole address.
      const via =
        upstreamProxy === undefined
          ? { host: address, port: target.port === "" ? 80 : Number(target.port), path: `${target.pathname}${target.search}` }
          : { host: upstreamProxy.host, port: upstreamProxy.port, path: target.href };

      if (upstreamProxy?.authorization !== undefined) {
        headers["proxy-authorization"] = upstreamProxy.authorization;
      }

      const upstream = httpRequest(
        { host: via.host, port: via.port, method: req.method, path: via.path, headers },
        (answer) => {
          res.writeHead(answer.statusCode ?? 502, answer.headers);
          answer.pipe(res);
        },
      );

      upstream.on("error", () => {
        if (res.headersSent) {
          res.destroy();
        } else {
          res.writeHead(502).end();
        }
      });
      req.pipe(upstream);
    })();
  });

  // HTTPS: the browser asks for a tunnel to host:port and speaks TLS through it, so the host is all the
  // proxy sees — and all it needs.
  server.on("connect", (req: IncomingMessage, client: Socket, head: Buffer): void => {
    sockets.add(client);
    client.on("close", () => {
      sockets.delete(client);
    });
    client.on("error", () => {
      client.destroy();
    });

    void (async (): Promise<void> => {
      const match = /^(\[[^\]]+\]|[^:]+):(\d+)$/.exec(req.url ?? "");
      const host = match?.[1];
      const port = Number(match?.[2]);

      if (host === undefined || !Number.isInteger(port) || port < 1 || port > 65535) {
        client.end("HTTP/1.1 400 Bad Request\r\n\r\n");

        return;
      }

      const address = await destination(host);

      if (address === null) {
        refuse(host);
        client.end("HTTP/1.1 403 Forbidden\r\n\r\n");

        return;
      }

      const opened =
        upstreamProxy === undefined
          ? await new Promise<{ socket: Socket; rest: Buffer } | null>((resolve) => {
              const socket = connect(port, address, () => {
                resolve({ socket, rest: Buffer.alloc(0) });
              });

              socket.on("error", () => {
                resolve(null);
              });
            })
          : // The name, not this side's address for it: the upstream proxy reaches the site from its network.
            await tunnelThrough(upstreamProxy, `${host}:${String(port)}`);

      if (opened === null) {
        client.end("HTTP/1.1 502 Bad Gateway\r\n\r\n");

        return;
      }

      const { socket: upstream, rest } = opened;

      sockets.add(upstream);
      upstream.on("close", () => {
        sockets.delete(upstream);
      });
      upstream.on("error", () => {
        client.destroy();
      });

      client.write("HTTP/1.1 200 Connection Established\r\n\r\n");

      if (rest.length > 0) {
        client.write(rest);
      }

      upstream.write(head);
      upstream.pipe(client);
      client.pipe(upstream);
    })();
  });

  server.on("connection", (socket: Socket): void => {
    sockets.add(socket);
    socket.on("close", () => {
      sockets.delete(socket);
    });
  });

  await new Promise<void>((resolve) => server.listen(0, "127.0.0.1", resolve));

  return {
    url: `http://127.0.0.1:${String((server.address() as AddressInfo).port)}`,
    close: async (): Promise<void> => {
      for (const socket of sockets) {
        socket.destroy();
      }

      await new Promise<void>((resolve) => {
        server.close(() => {
          resolve();
        });
      });
    },
  };
}
