// ─── Server-side share links ──────────────────────────────────────────────────
//
// Creates persistent, content-addressed share links (/s/<id>) backed by the server.

/**
 * POSTs build data to the share API and returns the generated short-link ID.
 * @param {{ classId: number, specId: number, builds: string[], labels?: string[], className: string, specName: string, layoutHash?: string|null }} payload Share payload
 * @returns {Promise<{ id: string }>} Resolves with short-link ID
 */
export async function createServerShare({
  classId,
  specId,
  builds,
  labels,
  className,
  specName,
  layoutHash,
}) {
  const apiBase = import.meta.env.BASE_URL + "api/share.php";
  const res = await fetch(apiBase, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      classId,
      specId,
      builds,
      labels,
      className,
      specName,
      layoutHash,
    }),
  });
  if (!res.ok) {
    const err = await res.json().catch(() => ({}));
    throw new Error(err.error ?? `HTTP ${res.status}`);
  }
  return res.json();
}
