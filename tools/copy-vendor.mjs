import {copyFile, mkdir} from 'node:fs/promises';
import path from 'node:path';

const root = process.cwd();
const output = path.join(root, 'build', 'assets', 'lib');
await mkdir(output, {recursive: true});

const assets = [
    ['node_modules/chart.js/dist/chart.umd.js', 'chart.umd.js'],
    ['node_modules/frappe-gantt/dist/frappe-gantt.umd.js', 'frappe-gantt.umd.js'],
    ['node_modules/frappe-gantt/dist/frappe-gantt.css', 'frappe-gantt.css'],
    ['node_modules/vis-timeline/dist/vis-timeline-graph2d.min.js', 'vis-timeline-graph2d.min.js'],
    ['node_modules/vis-timeline/styles/vis-timeline-graph2d.min.css', 'vis-timeline-graph2d.min.css'],
    ['node_modules/fullcalendar/all/global.js', 'fullcalendar.global.js'],
];

for (const [source, name] of assets) {
    await copyFile(path.join(root, source), path.join(output, name));
}

console.log(`Copied ${assets.length} pinned browser assets.`);
