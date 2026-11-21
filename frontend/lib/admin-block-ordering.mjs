export function moveBlockInOrder(blocks, blockId, oldIndex, newIndex) {
  const ids = blocks.map((block) => block.id);

  if (
    new Set(ids).size !== ids.length
    || blocks[oldIndex]?.id !== blockId
    || oldIndex < 0
    || newIndex < 0
    || oldIndex >= blocks.length
    || newIndex >= blocks.length
  ) {
    throw new Error("The block order is stale or incomplete.");
  }

  const reordered = [...blocks];
  const [movedBlock] = reordered.splice(oldIndex, 1);
  reordered.splice(newIndex, 0, movedBlock);

  return reordered;
}

export async function persistBlockOrderOptimistically(
  previousBlocks,
  reorderedBlocks,
  persist,
  applyBlocks,
) {
  const previousIds = previousBlocks.map((block) => block.id);
  const reorderedIds = reorderedBlocks.map((block) => block.id);
  const sameMembers =
    previousIds.length === reorderedIds.length
    && new Set(previousIds).size === previousIds.length
    && new Set(reorderedIds).size === reorderedIds.length
    && previousIds.every((id) => reorderedIds.includes(id));

  if (!sameMembers) {
    throw new Error("The reordered list must contain every block exactly once.");
  }

  applyBlocks(reorderedBlocks);

  try {
    const savedBlocks = await persist(reorderedIds);
    const savedIds = savedBlocks.map((block) => block.id);

    if (
      savedIds.length !== reorderedIds.length
      || savedIds.some((id, index) => id !== reorderedIds[index])
    ) {
      throw new Error("The server returned a different block order.");
    }

    applyBlocks(savedBlocks);
    return savedBlocks;
  } catch (error) {
    applyBlocks(previousBlocks);
    throw error;
  }
}

export function hasUnsavedLessonChanges({
  metadata,
  text,
  exercise,
  media,
  reordering,
}) {
  return Boolean(metadata || text || exercise || media || reordering);
}
