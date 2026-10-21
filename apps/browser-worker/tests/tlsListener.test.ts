import assert from "node:assert/strict";
import { test } from "node:test";
import { reloadOnChange, tlsFilesFrom } from "../src/tlsListener.js";

const FILES = { cert: "/run/kern/tls/server.crt", key: "/run/kern/tls/server.key" };

test("serves TLS only when given both halves of a certificate", () => {
  assert.deepEqual(tlsFilesFrom({ BROWSER_TLS_CERT: FILES.cert, BROWSER_TLS_KEY: FILES.key }), FILES);
  assert.equal(tlsFilesFrom({ BROWSER_TLS_CERT: FILES.cert }), null);
  assert.equal(tlsFilesFrom({ BROWSER_TLS_CERT: FILES.cert, BROWSER_TLS_KEY: "" }), null);
  assert.equal(tlsFilesFrom({}), null);
});

test("loads a renewed certificate into the running server, key and certificate together", () => {
  const loaded: { cert: Buffer; key: Buffer }[] = [];
  const pair = { cert: Buffer.from("new certificate"), key: Buffer.from("new key") };

  reloadOnChange({ setSecureContext: (options) => loaded.push(options) }, FILES, () => pair);

  assert.deepEqual(loaded, [pair]);
});

test("keeps the current certificate when the renewed one cannot be read", () => {
  const loaded: unknown[] = [];

  assert.doesNotThrow(() => {
    reloadOnChange({ setSecureContext: (options) => loaded.push(options) }, FILES, () => {
      throw new Error("ENOENT");
    });
  });
  assert.deepEqual(loaded, []);
});
