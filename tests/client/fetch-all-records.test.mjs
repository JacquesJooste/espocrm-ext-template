import assert from 'node:assert/strict';
import test from 'node:test';

import {
    fetchAllRecords,
    RECORD_LIST_PAGE_SIZE,
} from '../../src/files/client/custom/modules/elevate-resource-management/src/utils/fetch-all-records.js';

test('fetchAllRecords batches complete collections at EspoCRM safe page sizes', async () => {
    const source = Array.from({length: 450}, (_, index) => ({id: String(index + 1)}));
    const calls = [];
    const params = {
        where: [{type: 'equals', attribute: 'active', value: true}],
        orderBy: 'name',
        maxSize: 500,
        offset: 75,
    };
    const request = async (scope, requestParams) => {
        calls.push({scope, params: requestParams});

        return {
            list: source.slice(
                requestParams.offset,
                requestParams.offset + requestParams.maxSize
            ),
            total: source.length,
        };
    };

    const result = await fetchAllRecords('ElevateRmWorkItem', params, request);

    assert.deepEqual(result, source);
    assert.deepEqual(calls.map(call => call.params.offset), [0, 200, 400]);
    assert.ok(calls.every(call => call.params.maxSize === RECORD_LIST_PAGE_SIZE));
    assert.ok(calls.every(call => call.params.orderBy === 'name'));
    assert.ok(calls.every(call => call.params.where === params.where));
    assert.deepEqual(params, {
        where: [{type: 'equals', attribute: 'active', value: true}],
        orderBy: 'name',
        maxSize: 500,
        offset: 75,
    });
});

test('fetchAllRecords stops on an empty page when total is omitted', async () => {
    const source = Array.from({length: 400}, (_, index) => ({id: String(index + 1)}));
    const offsets = [];
    const request = async (_scope, params) => {
        offsets.push(params.offset);

        return {
            list: source.slice(params.offset, params.offset + params.maxSize),
        };
    };

    const result = await fetchAllRecords('User', {orderBy: 'name'}, request);

    assert.deepEqual(result, source);
    assert.deepEqual(offsets, [0, 200, 400]);
});

test('fetchAllRecords stops after a short page', async () => {
    const calls = [];
    const request = async (_scope, params) => {
        calls.push(params);

        return {list: [{id: '1'}, {id: '2'}]};
    };

    const result = await fetchAllRecords('ElevateRmInstance', {}, request);

    assert.deepEqual(result, [{id: '1'}, {id: '2'}]);
    assert.equal(calls.length, 1);
});

test('fetchAllRecords follows EspoCRM no-count pagination sentinels', async () => {
    const source = Array.from({length: 250}, (_, index) => ({id: String(index + 1)}));
    const offsets = [];
    const request = async (_scope, params) => {
        offsets.push(params.offset);
        const list = source.slice(params.offset, params.offset + params.maxSize);

        return {
            list,
            total: params.offset === 0 ? -1 : -2,
        };
    };

    const result = await fetchAllRecords('ElevateRmWorkItem', {}, request);

    assert.deepEqual(result, source);
    assert.deepEqual(offsets, [0, 200]);
});
