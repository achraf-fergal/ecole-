import assert from "node:assert/strict";
import test from "node:test";
import {
  hasUnsavedLessonChanges,
  moveBlockInOrder,
  persistBlockOrderOptimistically,
} from "../lib/admin-block-ordering.mjs";

const blocks = [
  { id: 11, title: "Text" },
  { id: 22, title: "Image" },
  { id: 33, title: "Exercise" },
];

test("moves a block by stable ID and rejects stale or duplicate source lists", () => {
  assert.deepEqual(
    moveBlockInOrder(blocks, 33, 2, 0).map((block) => block.id),
    [33, 11, 22],
  );
  assert.throws(() => moveBlockInOrder(blocks, 99, 2, 0), /stale or incomplete/);
  assert.throws(
    () => moveBlockInOrder([{ id: 11 }, { id: 11 }], 11, 0, 1),
    /stale or incomplete/,
  );
});

test("persists one complete ordered ID list and adopts the canonical response", async () => {
  const optimistic = moveBlockInOrder(blocks, 33, 2, 0);
  const applied = [];
  const calls = [];
  const saved = await persistBlockOrderOptimistically(
    blocks,
    optimistic,
    async (ids) => {
      calls.push(ids);
      return optimistic.map((block) => ({ ...block, saved: true }));
    },
    (nextBlocks) => applied.push(nextBlocks),
  );

  assert.deepEqual(calls, [[33, 11, 22]]);
  assert.deepEqual(applied.map((items) => items.map((item) => item.id)), [
    [33, 11, 22],
    [33, 11, 22],
  ]);
  assert.equal(saved[0].saved, true);
});

test("restores the prior timeline after a failed reorder request", async () => {
  const optimistic = moveBlockInOrder(blocks, 33, 2, 0);
  const applied = [];

  await assert.rejects(
    persistBlockOrderOptimistically(
      blocks,
      optimistic,
      async () => {
        throw new Error("network unavailable");
      },
      (nextBlocks) => applied.push(nextBlocks),
    ),
    /network unavailable/,
  );

  assert.deepEqual(applied.map((items) => items.map((item) => item.id)), [
    [33, 11, 22],
    [11, 22, 33],
  ]);
});

test("dirty-state detection covers each unsaved editor section", () => {
  const clean = {
    metadata: false,
    text: false,
    exercise: false,
    media: false,
    reordering: false,
  };

  assert.equal(hasUnsavedLessonChanges(clean), false);

  for (const key of Object.keys(clean)) {
    assert.equal(hasUnsavedLessonChanges({ ...clean, [key]: true }), true, key);
  }
});
