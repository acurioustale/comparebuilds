import { describe, test, afterEach } from "vitest";
import assert from "node:assert/strict";
import { createServerShare } from "./shareLink.js";

afterEach(() => {
  delete globalThis.fetch;
});

describe("shareLink createServerShare", () => {
  test("submits payload and returns id", async () => {
    let capturedBody = null;
    globalThis.fetch = async (url, options) => {
      capturedBody = JSON.parse(options.body);
      return {
        ok: true,
        json: async () => ({ id: "aB3xZ9mK" }),
      };
    };

    const out = await createServerShare({
      classId: 1,
      specId: 1,
      builds: ["CoPAAAA"],
      className: "Mage",
      specName: "Frost",
    });

    assert.deepStrictEqual(out, { id: "aB3xZ9mK" });
    assert.deepStrictEqual(capturedBody, {
      classId: 1,
      specId: 1,
      builds: ["CoPAAAA"],
      className: "Mage",
      specName: "Frost",
    });
  });

  test("throws on HTTP error", async () => {
    globalThis.fetch = async () => ({
      ok: false,
      status: 400,
      json: async () => ({ error: "Custom error" }),
    });

    await assert.rejects(
      async () => {
        await createServerShare({
          classId: 1,
          specId: 1,
          builds: ["CoPAAAA"],
          className: "Mage",
          specName: "Frost",
        });
      },
      (err) => {
        assert.strictEqual(err.message, "Custom error");
        return true;
      },
    );
  });
});
