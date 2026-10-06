import assert from "node:assert/strict";
import { test } from "node:test";
import { getCorrelationId, logger, runWithCorrelationId } from "../src/logger.js";

function captureStdout(fn: () => void): string {
  const original = process.stdout.write.bind(process.stdout);
  let captured = "";
  process.stdout.write = (chunk: string): boolean => {
    captured += chunk;
    return true;
  };
  try {
    fn();
  } finally {
    process.stdout.write = original;
  }
  return captured;
}

test("logger emits one JSON line with the core fields", () => {
  const output = captureStdout(() => {
    logger.info("something happened", { event: "test_event" });
  });

  const parsed = JSON.parse(output.trim()) as Record<string, unknown>;
  assert.equal(parsed.message, "something happened");
  assert.equal(parsed.level, "info");
  assert.equal(parsed.event, "test_event");
  assert.equal(typeof parsed.timestamp, "string");
});

test("logger drops any field outside the allowlist, even one smuggled past the type system", () => {
  const output = captureStdout(() => {
    // `fields` is a plain object, not a literal at the call site, so TS's
    // excess-property check does not fire here — exactly the situation a
    // caller with a loosely-typed value would be in.
    const fields = Object.assign({ event: "test_event" }, { cnp: "1234567890123" });
    logger.info("something happened", fields);
  });

  const parsed = JSON.parse(output.trim()) as Record<string, unknown>;
  assert.equal(parsed.event, "test_event");
  assert.equal("cnp" in parsed, false, "unlisted fields must never reach the log line");
});

test("correlation id set via runWithCorrelationId is attached to the log line", () => {
  const output = captureStdout(() => {
    runWithCorrelationId("corr-xyz", () => {
      logger.info("inside context");
    });
  });

  const parsed = JSON.parse(output.trim()) as Record<string, unknown>;
  assert.equal(parsed.correlationId, "corr-xyz");
});

test("log lines outside any correlation context carry no correlationId field", () => {
  const output = captureStdout(() => {
    logger.info("outside context");
  });

  const parsed = JSON.parse(output.trim()) as Record<string, unknown>;
  assert.equal("correlationId" in parsed, false);
});

test("getCorrelationId reflects the active context", () => {
  assert.equal(getCorrelationId(), undefined);
  runWithCorrelationId("corr-abc", () => {
    assert.equal(getCorrelationId(), "corr-abc");
  });
  assert.equal(getCorrelationId(), undefined);
});
