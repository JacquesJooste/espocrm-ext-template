export const RECORD_LIST_PAGE_SIZE = 200;

/**
 * Fetch every record visible to the current user without exceeding EspoCRM's
 * record-list page-size limit.
 *
 * @param {string} scope
 * @param {Object} [params]
 * @param {Function|null} [request]
 * @returns {Promise<Array>}
 */
export async function fetchAllRecords(scope, params = {}, request = null) {
    const getRequest = request ||
        ((requestScope, requestParams) =>
            Espo.Ajax.getRequest(requestScope, requestParams));
    const baseParams = {...params};
    const records = [];
    let offset = 0;

    delete baseParams.maxSize;
    delete baseParams.offset;

    while (true) {
        const response = await getRequest(scope, {
            ...baseParams,
            offset,
            maxSize: RECORD_LIST_PAGE_SIZE,
        });
        const page = Array.isArray(response?.list) ? response.list : [];

        records.push(...page);
        offset += page.length;

        const rawTotal = response?.total;
        const numericTotal = Number(rawTotal);
        const totalIsKnown = rawTotal !== null &&
            rawTotal !== undefined &&
            rawTotal !== '' &&
            Number.isFinite(numericTotal) &&
            numericTotal >= 0;
        const serverReportsNoMore = numericTotal === -2;

        if (
            page.length === 0 ||
            page.length < RECORD_LIST_PAGE_SIZE ||
            serverReportsNoMore ||
            (totalIsKnown && offset >= numericTotal)
        ) {
            break;
        }
    }

    return records;
}
