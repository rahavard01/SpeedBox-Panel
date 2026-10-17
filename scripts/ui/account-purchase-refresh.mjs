// Refresh only after a successful mutation. Read failures must never turn a
// completed, charged purchase into an apparent payment failure.
export async function speedboxRefreshPurchasedAccount({ http, target, lists, modal, isCurrent, afterModal }) {
  try {
    const id = Number(target.id);
    if (!(id > 0)) throw new Error('Missing account id');
    const response = await http.get('/users/' + id + '/details');
    const fresh = response.data?.data;
    if (!fresh || Number(fresh.id) !== id) throw new Error('Unexpected account response');
    for (const list of lists()) {
      if (!Array.isArray(list)) continue;
      for (const row of list) if (Number(row.id) === id) Object.assign(row, fresh);
    }
    if (isCurrent() && Number(modal.id) === id) {
      Object.assign(modal, fresh);
      if (afterModal) await afterModal(fresh, () => isCurrent() && Number(modal.id) === id);
    }
    return true;
  } catch {
    return false;
  }
}
