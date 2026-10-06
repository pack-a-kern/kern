import assert from "node:assert/strict";
import { after, before, describe, it } from "node:test";
import { chromium, type Browser, type Page } from "playwright";

import { Interpreter, StepFailedError } from "../src/playbook/interpreter.js";
import { Secrets } from "../src/playbook/secrets.js";
import type { Playbook } from "../src/playbook/types.js";

/**
 * The interpreter against a real Chromium and pages served from memory.
 *
 * No network and no site: `data:` URLs would be refused by the allow-list, so each test routes the
 * page itself. What is being tested is this worker's reading of a playbook, and a real browser is
 * the only honest way to test it — a fake page object would be testing the fake.
 */
let browser: Browser;

before(async () => {
  browser = await chromium.launch({ headless: true });
});

after(async () => {
  await browser.close();
});

/** A page serving `html` at the playbook's own host, so the allow-list is exercised too. */
async function pageShowing(html: string): Promise<Page> {
  const context = await browser.newContext();
  const page = await context.newPage();

  await page.route("https://example.test/**", async (route) => {
    await route.fulfill({ contentType: "text/html; charset=utf-8", body: html });
  });

  return page;
}

function playbook(sequences: Playbook["sequences"]): Playbook {
  return { version: 1, name: "test", allow: ["example.test"], sequences };
}

describe("Interpreter", () => {
  it("fills, clicks, and reports the outcome the page showed", async () => {
    const page = await pageShowing(`
      <form><input id="user"><button type="button" onclick="document.getElementById('ok').hidden=false">Go</button></form>
      <p id="ok" hidden>Signed in</p>
    `);

    const book = playbook({
      signIn: [
        { goto: "https://example.test/login" },
        { fill: { selector: "#user", value: "{{ secret.username }}" } },
        { click: { selector: "button" } },
        { waitFor: { any: [{ selector: "#ok", outcome: "authenticated" }] } },
      ],
    });

    const result = await new Interpreter(page, book, new Secrets({ username: "ana" })).run(book.sequences.signIn ?? []);

    assert.equal(result.outcome, "authenticated");
    assert.equal(await page.inputValue("#user"), "ana");
  });

  it("reports which of several outcomes the page reached, and why", async () => {
    const page = await pageShowing('<p id="err" class="error">Parolă greșită</p>');

    const book = playbook({
      signIn: [
        { goto: "https://example.test/login" },
        {
          waitFor: {
            any: [
              { selector: "#otp", outcome: "awaiting_challenge" },
              { selector: "#err", outcome: "failed", message: "#err" },
            ],
          },
        },
      ],
    });

    const result = await new Interpreter(page, book, new Secrets({})).run(book.sequences.signIn ?? []);

    // A login does not succeed or fail — it arrives at one of several pages, and only the page can
    // say which. The playbook names them; this worker reports which one won.
    assert.equal(result.outcome, "failed");
    assert.equal(result.message, "Parolă greșită");
  });

  it("refuses a destination the playbook did not allow", async () => {
    const page = await pageShowing("<p>ok</p>");
    const book = playbook({ go: [{ goto: "https://elsewhere.test/" }] });

    await assert.rejects(
      () => new Interpreter(page, book, new Secrets({})).run(book.sequences.go ?? []),
      /does not allow navigating/,
    );
  });

  it("extracts rows as data rather than HTML", async () => {
    const page = await pageShowing(`
      <table><tbody>
        <tr data-id="1"><td class="s">Decizie</td><td><a class="d" href="/a.pdf">x</a></td></tr>
        <tr data-id="2"><td class="s">Notificare</td><td><a class="d" href="/b.pdf">x</a></td></tr>
      </tbody></table>
    `);

    const book = playbook({
      inbox: [
        { goto: "https://example.test/inbox" },
        {
          extract: {
            rows: "tbody tr",
            fields: {
              id: { attr: "data-id" },
              subject: { text: "td.s" },
              href: { selector: "a.d", attr: "href" },
            },
            into: "messages",
          },
        },
      ],
    });

    const result = await new Interpreter(page, book, new Secrets({})).run(book.sequences.inbox ?? []);

    assert.deepEqual(result.data.messages, [
      { id: "1", subject: "Decizie", href: "/a.pdf" },
      { id: "2", subject: "Notificare", href: "/b.pdf" },
    ]);
  });

  it("refuses to screenshot a page a secret has been typed into", async () => {
    const page = await pageShowing('<input id="pass">');

    const book = playbook({
      shot: [
        { goto: "https://example.test/login" },
        { fill: { selector: "#pass", value: "{{ secret.password }}", sensitive: true } },
        { screenshot: {} },
      ],
    });

    // Once a password is in the page it stays visible to a camera, so capture is refused for the
    // rest of the run rather than for the one step — and refused out loud, so a caller expecting an
    // image is told why there is none.
    await assert.rejects(
      () => new Interpreter(page, book, new Secrets({ password: "hunter2" })).run(book.sequences.shot ?? []),
      StepFailedError,
    );
  });

  it("takes a screenshot when nothing sensitive has been entered", async () => {
    const page = await pageShowing("<h1>Hello</h1>");
    const book = playbook({ shot: [{ goto: "https://example.test/" }, { screenshot: { fullPage: true } }] });

    const result = await new Interpreter(page, book, new Secrets({})).run(book.sequences.shot ?? []);

    assert.equal(typeof result.data.screenshot, "string");
    assert.ok((result.data.screenshot as string).length > 0);
  });

  it("says what it was waiting for when the page shows none of it", async () => {
    const page = await pageShowing("<p>something else</p>");

    const book = playbook({
      signIn: [
        { goto: "https://example.test/" },
        { waitFor: { any: [{ selector: "#otp", outcome: "awaiting_challenge" }], timeout: 300 } },
      ],
    });

    await assert.rejects(
      () => new Interpreter(page, book, new Secrets({})).run(book.sequences.signIn ?? []),
      /awaiting_challenge/,
    );
  });
});
