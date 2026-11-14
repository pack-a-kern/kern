import assert from "node:assert/strict";
import { after, before, describe, it } from "node:test";
import { chromium, type Browser, type Page } from "playwright";

import { Interpreter, SourceTooLargeError, StepFailedError } from "../src/playbook/interpreter.js";
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
  it("carries on past a waitFor branch marked continue, so a guard can precede what it guards", async () => {
    // The shape a signed-in check needs: make sure the page is the one we think it is, *then* read it.
    // Without `continue` the guard has to be its own sequence and the caller joins two round-trips back
    // together — or, worse, reads the table first and reports an empty login page as an empty inbox.
    const page = await pageShowing(
      '<table class="mesaje"><tbody><tr data-id="7"><td class="detalii">o notificare</td></tr></tbody></table>',
    );

    const book = playbook({
      inbox: [
        { goto: "https://example.test/mesaje" },
        {
          waitFor: {
            any: [
              { selector: "table.mesaje", outcome: "signed_in", continue: true },
              { selector: "#credentials_table", outcome: "session_lost" },
            ],
          },
        },
        { extract: { rows: "table.mesaje tbody tr", fields: { id: { attr: "data-id" } }, into: "messages" } },
      ],
    });

    const result = await new Interpreter(page, book, new Secrets({})).run(book.sequences.inbox ?? []);

    // The guard's own outcome survives, and the step after it ran.
    assert.equal(result.outcome, "signed_in");
    assert.deepEqual(result.data.messages, [{ id: "7" }]);
  });

  it("stops at a branch not marked continue, leaving the steps after it unrun", async () => {
    const page = await pageShowing('<table id="credentials_table"><tbody><tr><td>Utilizator</td></tr></tbody></table>');

    const book = playbook({
      inbox: [
        { goto: "https://example.test/mesaje" },
        {
          waitFor: {
            any: [
              { selector: "table.mesaje", outcome: "signed_in", continue: true },
              { selector: "#credentials_table", outcome: "session_lost" },
            ],
          },
        },
        { extract: { rows: "table.mesaje tbody tr", fields: { id: { attr: "data-id" } }, into: "messages" } },
      ],
    });

    const result = await new Interpreter(page, book, new Secrets({})).run(book.sequences.inbox ?? []);

    assert.equal(result.outcome, "session_lost");
    // Nothing was read off the login page. An empty `messages` here would reach a caller as "no new
    // messages", which is a wrong answer rather than a missing one.
    assert.equal(result.data.messages, undefined);
  });
  it("reads a form going away as the outcome, which is how a sign-in reports success", async () => {
    // A portal decides for itself where to land after a login. Waiting for a particular page would mean
    // guessing at it; waiting for the credentials form to go is true wherever it lands.
    const page = await pageShowing(`
      <table id="credentials_table"><tbody>
        <tr><td><input name="password"></td></tr>
        <tr id="submit_row"><td><button type="button" onclick="document.getElementById('credentials_table').remove()">Autentificare</button></td></tr>
      </tbody></table>
    `);

    const book = playbook({
      challenge: [
        { goto: "https://example.test/my.policy" },
        { click: { selector: "button" } },
        {
          waitFor: {
            any: [
              { selector: "#credentials_table", state: "absent", outcome: "authenticated" },
              { selector: "#credentials_table_postheader font[color=\"red\"]", outcome: "failed" },
            ],
          },
        },
      ],
    });

    const result = await new Interpreter(page, book, new Secrets({})).run(book.sequences.challenge ?? []);

    assert.equal(result.outcome, "authenticated");
  });

  it("does not read a form that is still there as gone, which is what a refusal looks like", async () => {
    // The form re-renders with an error and the fields intact. If `absent` matched here, a wrong code
    // would be reported as a successful sign-in.
    const page = await pageShowing(`
      <table id="credentials_table"><tbody>
        <tr><td id="credentials_table_postheader"><font color="red">Utilizatorul sau parola nu sunt corecte.</font></td></tr>
        <tr><td><input name="password"></td></tr>
        <tr id="submit_row"><td><button type="button">Autentificare</button></td></tr>
      </tbody></table>
    `);

    const book = playbook({
      challenge: [
        { goto: "https://example.test/my.policy" },
        { click: { selector: "button" } },
        {
          waitFor: {
            any: [
              { selector: "#credentials_table", state: "absent", outcome: "authenticated" },
              {
                selector: '#credentials_table_postheader font[color="red"]',
                outcome: "failed",
                message: "#credentials_table_postheader",
              },
            ],
          },
        },
      ],
    });

    const result = await new Interpreter(page, book, new Secrets({})).run(book.sequences.challenge ?? []);

    assert.equal(result.outcome, "failed");
    assert.equal(result.message, "Utilizatorul sau parola nu sunt corecte.");
  });

  it("keeps the page its server sent, and refuses one after a click or past the limit", async () => {
    const page = await pageShowing('<p id="law">Art. 1</p><button>next</button>');
    const book = playbook({
      kept: [{ goto: "https://example.test/act" }, { source: true }],
      afterClick: [{ goto: "https://example.test/act" }, { click: { selector: "button" } }, { source: true }],
    });

    const kept = await new Interpreter(page, book, new Secrets({})).run(book.sequences.kept ?? []);

    assert.ok(kept.source !== undefined);
    assert.equal(kept.source.body.toString(), '<p id="law">Art. 1</p><button>next</button>');
    assert.equal(kept.source.status, 200);
    assert.equal(kept.source.url, "https://example.test/act");

    await assert.rejects(new Interpreter(page, book, new Secrets({})).run(book.sequences.afterClick ?? []), StepFailedError);
    await assert.rejects(new Interpreter(page, book, new Secrets({}), 10).run(book.sequences.kept ?? []), SourceTooLargeError);
  });

  it("keeps what a click made the page fetch, and refuses to capture outside the allow-list", async () => {
    // The same address asked twice, as a chart does; only the second is the one wanted.
    const page = await pageShowing(
      `<button onclick="fetch('https://example.test/api/prices?range=1D').then(() => fetch('https://example.test/api/prices?range=6M'))">6m</button>`,
    );

    // The chart's data, answered as JSON where the page answers HTML.
    await page.route("https://example.test/api/**", async (route) => {
      await route.fulfill({ contentType: "application/json", body: route.request().url().endsWith("6M") ? '{"c":[101,98]}' : '{"c":[1]}' });
    });

    const book = playbook({
      captured: [
        { goto: "https://example.test/act" },
        { click: { selector: "button", capture: { url: "https://example.test/api/prices", query: { range: "6M" } } } },
        { source: true },
      ],
      elsewhere: [{ goto: "https://example.test/act" }, { click: { selector: "button", capture: { url: "https://other.test/api" } } }],
    });

    const kept = await new Interpreter(page, book, new Secrets({})).run(book.sequences.captured ?? []);

    assert.ok(kept.source !== undefined);
    assert.equal(kept.source.body.toString(), '{"c":[101,98]}');
    assert.equal(kept.source.url, "https://example.test/api/prices?range=6M");
    assert.equal(kept.source.contentType, "application/json");

    await assert.rejects(new Interpreter(page, book, new Secrets({})).run(book.sequences.elsewhere ?? []));
  });

  it("reads a field as the page shows it when asked, where markup alone runs words together", async () => {
    const page = await pageShowing('<div class="row"><p><b>LEGE nr. 227 din 8 septembrie 2015</b><br>privind Codul fiscal</p></div>');
    const book = playbook({
      read: [
        { goto: "https://example.test/results" },
        { extract: { rows: ".row", fields: { markup: { selector: "p" }, shown: { selector: "p", rendered: true } }, into: "acts" } },
      ],
    });

    const result = await new Interpreter(page, book, new Secrets({})).run(book.sequences.read ?? []);
    const [row] = result.data.acts as readonly (Record<string, string> | undefined)[];

    assert.ok(row !== undefined);
    assert.equal(row.markup, "LEGE nr. 227 din 8 septembrie 2015privind Codul fiscal");
    assert.equal(row.shown?.replace(/\s+/g, " "), "LEGE nr. 227 din 8 septembrie 2015 privind Codul fiscal");
  });

  it("gives a field a row does not have as null at once, not after waiting for it", async () => {
    const page = await pageShowing('<div class="row"><p>first</p><span class="issuer">Guvernul</span></div><div class="row"><p>second</p></div>');
    const book = playbook({
      read: [
        { goto: "https://example.test/results" },
        { extract: { rows: ".row", fields: { name: { selector: "p" }, issuer: { selector: ".issuer", rendered: true } }, into: "acts" } },
      ],
    });

    const started = Date.now();
    const result = await new Interpreter(page, book, new Secrets({})).run(book.sequences.read ?? []);

    assert.deepEqual(result.data.acts, [
      { name: "first", issuer: "Guvernul" },
      { name: "second", issuer: null },
    ]);
    assert.ok(Date.now() - started < 5_000, "a missing field must not wait for the step's timeout");
  });
});
