import assert from "node:assert/strict";
import { createServer, type Server } from "node:http";
import type { AddressInfo } from "node:net";
import { describe, it } from "node:test";

import { createApp } from "../src/app.js";
import { SessionManager } from "../src/sessions/sessionManager.js";
import { createPlaywrightBrowserContext } from "../src/sessions/browserContextFactory.js";

const TOKEN = "test-internal-token";

const PLAYBOOK = {
  version: 1,
  name: "example",
  allow: ["example.test"],
  sequences: { noop: [{ waitFor: { any: [{ selector: "body", outcome: "ready" }], timeout: 2000 } }] },
};

/**
 * The run API end to end, against a real browser.
 *
 * A fake context would make these tests about the fake. What is worth knowing is that a playbook
 * posted over HTTP reaches a browser and comes back as an outcome — which only a browser can show.
 */
async function withServer(
  fn: (baseUrl: string) => Promise<void>,
  options: { readonly authMode?: "token" | "network"; readonly maxWaiting?: number } = {},
): Promise<void> {
  const manager = new SessionManager({ ttlMs: 60_000, createBrowserContext: createPlaywrightBrowserContext, maxSessions: 8 });
  const server: Server = createServer(
    createApp(manager, {
      authMode: options.authMode ?? "token",
      internalAuthToken: TOKEN,
      concurrency: 1,
      maxWaiting: options.maxWaiting ?? 4,
      maxSourceBytes: 1024 * 1024,
    }),
  );

  await new Promise<void>((resolve) => server.listen(0, resolve));

  try {
    await fn(`http://127.0.0.1:${String((server.address() as AddressInfo).port)}`);
  } finally {
    await manager.closeAll();
    await new Promise<void>((resolve) => server.close(() => resolve()));
  }
}

function post(url: string, body: unknown, token: string | null = TOKEN): Promise<Response> {
  return fetch(url, {
    method: "POST",
    headers: { "content-type": "application/json", ...(token === null ? {} : { authorization: `Bearer ${token}` }) },
    body: JSON.stringify(body),
  });
}

describe("the run API", () => {
  it("refuses a playbook that is not valid, and says where", async () => {
    await withServer(async (base) => {
      const response = await post(`${base}/v1/run`, { playbook: { version: 1, name: "x", sequences: {} } });

      assert.equal(response.status, 422);

      const body = (await response.json()) as { error: string; problems: string[] };

      assert.equal(body.error, "playbook_invalid");
      // The problems name their path, because a playbook is written by hand and arrives over HTTP.
      assert.ok(body.problems.some((problem) => problem.includes("allow")));
    });
  });

  it("refuses a secret the playbook asked for and the request did not send", async () => {
    await withServer(async (base) => {
      const playbook = {
        ...PLAYBOOK,
        // No navigation: the placeholder is resolved before the page is touched, so this fails
        // where it should without needing a site to exist.
        sequences: { go: [{ fill: { selector: "#u", value: "{{ secret.user }}" } }] },
      };

      const response = await post(`${base}/v1/run`, { playbook, sequence: "go", secrets: {} });

      assert.equal(response.status, 422);
      assert.equal(((await response.json()) as { error: string }).error, "secret_missing");
    });
  });

  it("refuses a destination the playbook did not allow", async () => {
    await withServer(async (base) => {
      const playbook = { ...PLAYBOOK, sequences: { go: [{ goto: "https://elsewhere.test/" }] } };
      const response = await post(`${base}/v1/run`, { playbook, sequence: "go" });

      assert.equal(response.status, 403);
      assert.equal(((await response.json()) as { error: string }).error, "destination_refused");
    });
  });

  it("wants the token when it is configured to", async () => {
    await withServer(async (base) => {
      assert.equal((await post(`${base}/v1/run`, { playbook: PLAYBOOK }, null)).status, 401);
    });
  });

  it("lets anything through when told the network is the boundary", async () => {
    // A deployment may decide that nothing but one caller can reach the port. It has to say so —
    // which is why the default is the other way, and why an unset variable never lands here.
    await withServer(
      async (base) => {
        assert.notEqual((await post(`${base}/v1/run`, { playbook: PLAYBOOK, sequence: "noop" }, null)).status, 401);
      },
      { authMode: "network" },
    );
  });

  it("keeps a session open across sequences and closes it when asked", async () => {
    await withServer(async (base) => {
      const opened = await post(`${base}/v1/sessions`, { playbook: PLAYBOOK });

      assert.equal(opened.status, 201);

      const { session_id: id } = (await opened.json()) as { session_id: string };

      const listed = (await (await fetch(`${base}/v1/sessions`, { headers: { authorization: `Bearer ${TOKEN}` } })).json()) as {
        sessions: { id: string }[];
      };

      assert.ok(listed.sessions.some((session) => session.id === id));

      const closed = await fetch(`${base}/v1/sessions/${id}`, {
        method: "DELETE",
        headers: { authorization: `Bearer ${TOKEN}` },
      });

      assert.equal(closed.status, 200);
    });
  });

  it("answers 404 for a session it does not have", async () => {
    await withServer(async (base) => {
      const response = await post(`${base}/v1/sessions/00000000-0000-0000-0000-000000000000/run`, {
        playbook: PLAYBOOK,
        sequence: "noop",
      });

      assert.equal(response.status, 404);
    });
  });

  it("reports what it has been doing without saying where it went", async () => {
    await withServer(async (base) => {
      const response = await fetch(`${base}/v1/stats`, { headers: { authorization: `Bearer ${TOKEN}` } });
      const stats = (await response.json()) as Record<string, unknown>;

      assert.equal(response.status, 200);
      assert.ok("runs" in stats && "queue" in stats && "sessions_open" in stats);
      // A stats endpoint is the easiest place to leak by accident, because nobody thinks of it as
      // handling data. Counts and names only — never a URL and never a playbook.
      assert.ok(!JSON.stringify(stats).includes("example.test"));
    });
  });

  it("answers /v1/source with the page as its server sent it, not as JSON", async () => {
    await withSite({ "/act": [200, "<html><body><p>Art. 1</p><script>document.body.innerHTML='rewritten'</script></body></html>"] }, async (site) => {
      await withServer(async (base) => {
        const response = await post(`${base}/v1/source`, {
          playbook: sitePlaybook(),
          sequence: "page",
          secrets: { url: `${site}/act` },
        });

        assert.equal(response.status, 200);
        assert.match(response.headers.get("content-type") ?? "", /^text\/html/);
        assert.equal(response.headers.get("x-source-url"), `${site}/act`);
        // The served bytes, not the document after its script rewrote it.
        assert.match(await response.text(), /<p>Art\. 1<\/p>/);
      });
    });
  });

  it("says a page the server refused, rather than handing back its error page as the page", async () => {
    await withSite({ "/gone": [404, "<html>Not found</html>"] }, async (site) => {
      await withServer(async (base) => {
        const response = await post(`${base}/v1/source`, { playbook: sitePlaybook(), sequence: "page", secrets: { url: `${site}/gone` } });

        assert.equal(response.status, 502);
        assert.equal(((await response.json()) as { error: string; status: number }).status, 404);
      });
    });
  });

  it("refuses a page larger than it hands back, whole", async () => {
    await withSite({ "/big": [200, "x".repeat(2 * 1024 * 1024)] }, async (site) => {
      await withServer(async (base) => {
        const response = await post(`${base}/v1/source`, { playbook: sitePlaybook(), sequence: "page", secrets: { url: `${site}/big` } });

        assert.equal(response.status, 413);
        assert.deepEqual(await response.json(), { error: "source_too_large", limit: 1024 * 1024 });
      });
    });
  });

  it("refuses /v1/source for a sequence that kept no page", async () => {
    await withServer(async (base) => {
      const response = await post(`${base}/v1/source`, { playbook: PLAYBOOK, sequence: "noop" });

      assert.equal(response.status, 422);
      assert.equal(((await response.json()) as { error: string }).error, "source_missing");
    });
  });
});

/** A playbook allowed onto the local test site only, opening the page it is sent and keeping it. */
function sitePlaybook(): unknown {
  return { version: 1, name: "site", allow: ["127.0.0.1"], sequences: { page: [{ goto: "{{ secret.url }}" }, { source: true }] } };
}

/** A real HTTP server answering each path with its status and body, for the browser to open. */
async function withSite(pages: Record<string, readonly [number, string]>, fn: (baseUrl: string) => Promise<void>): Promise<void> {
  const site = createServer((req, res) => {
    const [status, body] = pages[req.url ?? ""] ?? [404, ""];

    res.writeHead(status, { "content-type": "text/html; charset=utf-8" }).end(body);
  });

  await new Promise<void>((resolve) => site.listen(0, "127.0.0.1", resolve));

  try {
    await fn(`http://127.0.0.1:${String((site.address() as AddressInfo).port)}`);
  } finally {
    await new Promise<void>((resolve) => site.close(() => resolve()));
  }
}
