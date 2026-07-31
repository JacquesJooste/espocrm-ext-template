import assert from 'node:assert/strict';
import test from 'node:test';

import {firstName, reportEntry, reportEntryText} from '../../src/files/client/custom/modules/elevate-resource-management/src/utils/report-entry.js';

test('firstName returns the first non-whitespace-delimited name', () => {
    assert.equal(firstName('  Ada Lovelace '), 'Ada');
    assert.equal(firstName(''), '');
});

test('reportEntry creates the requested time-log presentation', () => {
    assert.deepEqual(reportEntry({
        dateStart: '2026-07-30 09:15:00',
        dateEnd: '2026-07-30 10:45:00',
        attendeeNames: ['Ada Lovelace', 'Grace Hopper'],
        workItemDescription: 'Review the billing workflow.',
        workNote: 'Customer approval needed.',
        userFlagged: true,
    }), {
        date: '2026-07-30',
        start: '09:15',
        finish: '10:45',
        teamCount: 2,
        teamNames: 'Ada, Grace',
        content: 'Review the billing workflow.',
        note: 'Customer approval needed.',
        flagged: true,
    });
});

test('reportEntryText omits an empty note section', () => {
    const text = reportEntryText({
        dateStart: '2026-07-30 09:00:00',
        dateEnd: '2026-07-30 09:30:00',
        attendeeNames: ['Ada Lovelace'],
        activities: 'Prepare report',
    });

    assert.equal(text, '2026-07-30\n09:00 - 09:30\nTeam of 1: Ada\n\nPrepare report');
});

test('reportEntryText renders legacy block activities as readable lines', () => {
    const text = reportEntryText({
        dateStart: '2026-07-30 09:00:00',
        dateEnd: '2026-07-30 09:30:00',
        attendeeNames: [],
        activities: ['Prepare report', 'Send report'],
    });

    assert.match(text, /Prepare report\nSend report$/);
});
