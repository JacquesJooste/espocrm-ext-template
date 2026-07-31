function dateParts(value) {
    const normalized = String(value || '').replace('T', ' ');
    const [date = '', time = ''] = normalized.split(' ');

    return {date, time: time.slice(0, 5)};
}

export function firstName(value) {
    return String(value || '').trim().split(/\s+/)[0] || '';
}

export function reportEntry(item) {
    const start = dateParts(item.dateStart);
    const end = dateParts(item.dateEnd);
    const names = (item.attendeeNames || []).map(firstName).filter(Boolean);
    const activities = Array.isArray(item.activities)
        ? item.activities.join('\n')
        : item.activities;
    const content = item.workItemDescription || activities ||
        item.workItemName || item.blockName || '';
    const note = String(item.workNote || '').trim();

    return {
        date: start.date,
        start: start.time,
        finish: end.time,
        teamCount: names.length,
        teamNames: names.join(', '),
        content: String(content),
        note,
        flagged: Boolean(item.userFlagged),
    };
}

export function reportEntryText(item) {
    const entry = reportEntry(item);
    const lines = [
        entry.date,
        `${entry.start} - ${entry.finish}`,
        `Team of ${entry.teamCount}: ${entry.teamNames}`,
        '',
        entry.content,
    ];

    if (entry.flagged || entry.note) {
        lines.push('', `${entry.flagged ? 'FLAGGED' : 'Note'}${entry.note ? `: ${entry.note}` : ''}`);
    }

    return lines.join('\n');
}
