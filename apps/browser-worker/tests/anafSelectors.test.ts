import assert from "node:assert/strict";
import { test } from "node:test";
import { chromium, type Browser } from "playwright";

/**
 * The selectors `packages/tax-romania/config/playbooks/anaf_spv.php` uses to tell ANAF's pages apart,
 * checked against the HTML those pages actually serve.
 *
 * This worker knows no websites, and these fixtures are not it learning one: they are three DOM shapes
 * observed on a real portal, kept here because this is where a browser is available to run them
 * through. What is under test is a *claim about Playwright's selector engine* — that an absence can be
 * read safely when it is anchored on something that parses after it. That claim was first made in
 * prose, in a comment, and prose is exactly the wrong place for it.
 *
 * The third fixture is the one worth having. A table that has begun parsing but not reached its
 * username row looks, to a naive "no username field" selector, exactly like the page that has no
 * username field at all — so a sign-in would announce "ANAF asked for the code" while landing on a
 * refusal.
 */
const CREDENTIALS_TABLE = (rows: string): string =>
  `<!doctype html><meta charset="utf-8"><body><table id="credentials_table"><tbody>${rows}</tbody></table></body>`;

const HEADER = `<tr><td id="credentials_table_header">Autentificare username si parola</td></tr>`;
const USERNAME_ROW = `<tr><td><label>Utilizator</label><input type="text" name="username" id="input_1"></td></tr>`;
const PASSWORD_ROW = `<tr><td><label>Parola</label><input type="password" name="password" id="input_2"></td></tr>`;
const SUBMIT_ROW = `<tr id="submit_row"><td><input type="submit" value="Autentificare"></td></tr>`;
const RED_ERROR = `<tr><td id="credentials_table_postheader"><font color="red">Utilizatorul sau parola nu sunt corecte. Va rugam să incercati din nou.</font></td></tr>`;
const EMPTY_POSTHEADER = `<tr><td id="credentials_table_postheader"></td></tr>`;

/** Copied from the playbook. A divergence here is the point of the test. */
const AWAITING_CHALLENGE = '#credentials_table:has(#submit_row):not(:has(input[name="username"]))';
const FAILED = '#credentials_table_postheader font[color="red"]';
const FAILED_MESSAGE = "#credentials_table_postheader";

const FIXTURES = {
  login: CREDENTIALS_TABLE(HEADER + EMPTY_POSTHEADER + USERNAME_ROW + PASSWORD_ROW + SUBMIT_ROW),
  refused: CREDENTIALS_TABLE(HEADER + RED_ERROR + USERNAME_ROW + PASSWORD_ROW + SUBMIT_ROW),
  codePage: CREDENTIALS_TABLE(HEADER + EMPTY_POSTHEADER + PASSWORD_ROW + SUBMIT_ROW),
  halfParsed: CREDENTIALS_TABLE(HEADER),
};

async function matches(browser: Browser, html: string, selector: string): Promise<number> {
  const page = await browser.newPage();
  try {
    await page.setContent(html);
    return await page.locator(selector).count();
  } finally {
    await page.close();
  }
}

test("the challenge selector matches only the page that has dropped its username field", async () => {
  const browser = await chromium.launch({ headless: true });

  try {
    assert.equal(await matches(browser, FIXTURES.codePage, AWAITING_CHALLENGE), 1, "code page");
    assert.equal(await matches(browser, FIXTURES.login, AWAITING_CHALLENGE), 0, "login page");
    assert.equal(await matches(browser, FIXTURES.refused, AWAITING_CHALLENGE), 0, "refused login");
    // The race. Without `:has(#submit_row)` this is 1, and a refusal gets reported as a challenge.
    assert.equal(await matches(browser, FIXTURES.halfParsed, AWAITING_CHALLENGE), 0, "half-parsed table");
  } finally {
    await browser.close();
  }
});

test("the failure selector matches a refusal and nothing else, and its words can be read back", async () => {
  const browser = await chromium.launch({ headless: true });

  try {
    assert.equal(await matches(browser, FIXTURES.refused, FAILED), 1, "refused login");
    // The cell exists on every one of these pages. Matching the cell rather than the red element inside
    // it would make every page look like a failure.
    assert.equal(await matches(browser, FIXTURES.login, FAILED), 0, "login page");
    assert.equal(await matches(browser, FIXTURES.codePage, FAILED), 0, "code page");

    const page = await browser.newPage();
    await page.setContent(FIXTURES.refused);
    const message = (await page.locator(FAILED_MESSAGE).first().textContent())?.trim();
    await page.close();

    // Read from the cell, not the `font`: nested `<font color="red">` tags were observed, and reading
    // the inner one would return the sentence twice.
    assert.equal(message, "Utilizatorul sau parola nu sunt corecte. Va rugam să incercati din nou.");
  } finally {
    await browser.close();
  }
});
