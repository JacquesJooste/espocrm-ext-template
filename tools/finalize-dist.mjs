import {createHash} from 'node:crypto';
import {copyFile, readdir, readFile, rename, stat, writeFile} from 'node:fs/promises';
import path from 'node:path';

const build = path.join(process.cwd(), 'build');
const packageData = JSON.parse(await readFile(path.join(process.cwd(), 'package.json'), 'utf8'));
const expected = path.join(build, `elevate-resource-management-${packageData.version}.zip`);
const bundlePath = path.join(
    build,
    'assets/lib/module-elevate-resource-management.js',
);
const bundleSource = await readFile(bundlePath, 'utf8');
const requiredBundleModules = [
    'modules/elevate-resource-management/controllers/workspace',
    'modules/elevate-resource-management/handlers/target-list',
    'modules/elevate-resource-management/handlers/target-list-launcher',
    'modules/elevate-resource-management/handlers/target-controls',
    'modules/elevate-resource-management/handlers/instance-guidance',
    'modules/elevate-resource-management/utils/fetch-all-records',
    'modules/elevate-resource-management/views/time-action-modal',
    'modules/elevate-resource-management/views/work-block-editor',
    'modules/elevate-resource-management/views/fields/default-work-blocks',
    'modules/elevate-resource-management/views/fields/duration-quarter-hour',
    'modules/elevate-resource-management/views/fields/json-array',
    'modules/elevate-resource-management/views/fields/target-entity-type',
    'modules/elevate-resource-management/views/fields/target-field',
    'modules/elevate-resource-management/views/fields/target-status',
    'modules/elevate-resource-management/views/fields/target-status-list',
    'modules/elevate-resource-management/views/workspace',
];

for (const id of requiredBundleModules) {
    if (!bundleSource.includes(`define("${id}"`)) {
        throw new Error(`Bundle does not define required named module '${id}'.`);
    }
}

if (bundleSource.includes('define([')) {
    throw new Error('Bundle contains an anonymous AMD module.');
}

if (/define\("[^"]+",\[[^\]]*"\.\.?\/[^"]+"/.test(bundleSource)) {
    throw new Error('Bundle contains a relative dependency that EspoCRM cannot resolve safely.');
}

const files = await readdir(build);
const candidates = files.filter(name => name.toLowerCase().endsWith('.zip'));

if (!candidates.length) throw new Error('Extension builder did not produce a ZIP.');

const candidatesByAge = await Promise.all(candidates.map(async name => ({
    name,
    modifiedAt: (await stat(path.join(build, name))).mtimeMs,
})));
const source = path.join(
    build,
    candidatesByAge.sort((a, b) => b.modifiedAt - a.modifiedAt)[0].name,
);
if (source !== expected) {
    try { await rename(source, expected); }
    catch { await copyFile(source, expected); }
}

const digest = createHash('sha256').update(await readFile(expected)).digest('hex');
await writeFile(`${expected}.sha256`, `${digest}  ${path.basename(expected)}\n`);
console.log(`Created ${expected} and SHA-256 checksum.`);
