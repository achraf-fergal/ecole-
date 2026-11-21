export interface BlockWithId {
  id: number;
}

export interface UnsavedLessonEditorState {
  metadata: boolean;
  text: boolean;
  exercise: boolean;
  media: boolean;
  reordering: boolean;
}

export function moveBlockInOrder<T extends BlockWithId>(
  blocks: T[],
  blockId: number,
  oldIndex: number,
  newIndex: number,
): T[];

export function persistBlockOrderOptimistically<T extends BlockWithId>(
  previousBlocks: T[],
  reorderedBlocks: T[],
  persist: (orderedIds: number[]) => Promise<T[]>,
  applyBlocks: (blocks: T[]) => void,
): Promise<T[]>;

export function hasUnsavedLessonChanges(
  state: UnsavedLessonEditorState,
): boolean;
