import { createContext, useContext } from "react";

// Shared search state so any tree node (TalentNode, HeatmapNode) can dim/emphasise
// itself without threading props through TreePanel / SideBySideDiff / Heatmap. The
// search box (in MainView) is the single provider.
//
//   active   — true while there is a non-empty query
//   matchIds — Set<number> of node ids that match (empty when inactive)
export const SearchContext = createContext({ active: false, matchIds: null });

// The blue ring drawn around a node that matches the active search query.
const SEARCH_RING =
  "0 0 0 2px rgba(110,200,255,0.95), 0 0 12px rgba(110,200,255,0.55)";

/**
 * Per-node search styling derived from the active query. Shared by TalentNode and
 * HeatmapNode so the dim/ring behaviour can't drift between the two renderers.
 *
 * @param {number} nodeId
 * @returns {{
 *   searchHit: boolean,      // this node matches the query
 *   searchDimmed: boolean,   // a query is active and this node is NOT a match
 *   effOpacity: (base:number)=>number, // clamps a base opacity down when dimmed
 *   searchRing: string|null  // the match-ring shadow string, or null
 * }}
 */
export function useSearchHighlight(nodeId) {
  const { active, matchIds } = useContext(SearchContext);
  const searchHit = active && matchIds ? matchIds.has(nodeId) : false;
  const searchDimmed = active && matchIds ? !searchHit : false;
  return {
    searchHit,
    searchDimmed,
    effOpacity: (base) => (searchDimmed ? Math.min(base, 0.12) : base),
    searchRing: searchHit ? SEARCH_RING : null,
  };
}
