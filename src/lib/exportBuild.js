import { generateBuildString, heroGateSelection } from "./buildString.js";
import {
  activeHeroSubtree,
  prunedExportSelection,
  sectionPoints,
} from "./spendRules.js";

/**
 * Generates a valid build string from an interactive selection by pruning
 * inactive hero subtrees, injecting the hero gate selection, and collecting
 * granted node IDs before encoding.
 *
 * @param {object} treeData Spec tree data definition
 * @param {Record<number, { pointsInvested: number, entryChosen: number|null }>} selected Interactive selection state
 * @param {number} specId Active spec ID
 * @param {Array<{ id: number, maxRanks: number, choices: Array<{maxRanks:number}>|null }>} classNodes Full class node list
 * @returns {string} Base64 build string
 */
export function buildExportString(treeData, selected, specId, classNodes) {
  if (!treeData || !specId || !classNodes) return "";
  const activeSub = activeHeroSubtree(treeData.nodes, selected);
  const heroSpent = sectionPoints("hero", treeData.nodes, selected);

  const exportSelection = prunedExportSelection(
    treeData.nodes,
    selected,
    activeSub,
  );
  const gateSel = heroGateSelection(
    heroSpent,
    activeSub != null && activeSub === treeData.heroSubtrees?.right?.name,
  );
  if (gateSel && treeData.heroGateNodeId != null) {
    exportSelection[treeData.heroGateNodeId] = gateSel;
  }
  const grantedIds = new Set(
    treeData.nodes
      .filter(
        (n) =>
          n.alreadyGranted &&
          (n.treeType !== "hero" || n.heroSubtree === activeSub),
      )
      .map((n) => n.id),
  );
  return generateBuildString(exportSelection, specId, classNodes, grantedIds);
}
