import {readFile, readdir} from 'node:fs/promises';
import {spawnSync} from 'node:child_process';
import path from 'node:path';

const root = process.cwd();
const failures = [];
const placeholder = /\{@[A-Za-z][A-Za-z0-9]*\}/;
const moduleRoot =
    path.join(root, 'src/files/custom/Espo/Modules/ElevateResourceManagement');
const entityDefsRoot = path.join(moduleRoot, 'Resources/metadata/entityDefs');

async function walk(directory) {
    const result = [];
    for (const entry of await readdir(directory, {withFileTypes: true})) {
        const full = path.join(directory, entry.name);
        if (entry.isDirectory()) {
            if (!['.git', 'build', 'node_modules', 'site', 'vendor'].includes(entry.name)) {
                result.push(...await walk(full));
            }
        } else {
            result.push(full);
        }
    }
    return result;
}

for (const file of await walk(root)) {
    const relative = path.relative(root, file);
    if (!/\.(json|js|mjs|php|md|css|toml|neon)$/.test(file)) continue;
    const text = await readFile(file, 'utf8');
    if (placeholder.test(text)) failures.push(`${relative}: unresolved template placeholder`);
    if (file.endsWith('.json')) {
        try { JSON.parse(text); } catch (error) { failures.push(`${relative}: invalid JSON: ${error.message}`); }
    }
}

for (const required of [
    'extension.json',
    'src/files/custom/Espo/Modules/ElevateResourceManagement/Resources/routes.json',
    'src/files/custom/Espo/Modules/ElevateResourceManagement/Resources/metadata/entityDefs/ElevateRmTimeEntry.json',
    'src/files/custom/Espo/Modules/ElevateResourceManagement/Resources/metadata/entityDefs/ElevateRmWorkItem.json',
    'src/files/custom/Espo/Modules/ElevateResourceManagement/Resources/metadata/entityDefs/ElevateRmWorkBlockItem.json',
    'src/files/custom/Espo/Modules/ElevateResourceManagement/Resources/metadata/entityDefs/ElevateRmWorkBlockRun.json',
    'src/files/custom/Espo/Modules/ElevateResourceManagement/Resources/metadata/entityDefs/ElevateRmWorkItemRun.json',
    'src/files/custom/Espo/Modules/ElevateResourceManagement/Domain/LegacyMigration.php',
    'src/files/client/custom/modules/elevate-resource-management/src/views/workspace.js',
    'src/scripts/BeforeInstall.php',
    'src/scripts/AfterInstall.php',
]) {
    try { await readFile(path.join(root, required)); } catch { failures.push(`${required}: required file missing`); }
}

const routesPath =
    'src/files/custom/Espo/Modules/ElevateResourceManagement/Resources/routes.json';
const routes = JSON.parse(await readFile(path.join(root, routesPath), 'utf8'));
for (const [method, route] of [
    ['get', '/ElevateResourceManagement/settings'],
    ['put', '/ElevateResourceManagement/settings'],
    ['post', '/ElevateResourceManagement/work-blocks'],
    ['post', '/ElevateResourceManagement/packages/:id/work-blocks'],
    ['post', '/ElevateResourceManagement/timers/start'],
    ['post', '/ElevateResourceManagement/timers/:id/stop'],
    ['post', '/ElevateResourceManagement/scheduled-blocks/:id/reschedule-remaining'],
]) {
    if (!routes.some(item => item.method === method && item.route === route)) {
        failures.push(`${routesPath}: missing ${method.toUpperCase()} ${route}`);
    }
}

const scheduledDefsPath = path.join(entityDefsRoot, 'ElevateRmScheduledBlock.json');
const scheduledDefs = JSON.parse(await readFile(scheduledDefsPath, 'utf8'));
if (!scheduledDefs.fields?.createdAt || !scheduledDefs.fields?.modifiedAt) {
    failures.push(
        `${path.relative(root, scheduledDefsPath)}: calendar event requires createdAt and modifiedAt`
    );
}
if (!scheduledDefs.fields?.assignedUsers || scheduledDefs.fields?.users) {
    failures.push(
        `${path.relative(root, scheduledDefsPath)}: calendar attendees must use assignedUsers`
    );
}
if (scheduledDefs.links?.assignedUsers?.relationName !== 'elevateRmScheduledBlockUser') {
    failures.push(
        `${path.relative(root, scheduledDefsPath)}: assignedUsers must preserve the existing relation table`
    );
}

const timeEntryDefsPath = path.join(entityDefsRoot, 'ElevateRmTimeEntry.json');
const timeEntryDefs = JSON.parse(await readFile(timeEntryDefsPath, 'utf8'));
for (const relation of ['workBlockRun', 'workItemRun', 'users']) {
    if (!timeEntryDefs.fields?.[relation] || !timeEntryDefs.links?.[relation]) {
        failures.push(
            `${path.relative(root, timeEntryDefsPath)}: missing runtime relation '${relation}'`
        );
    }
}

const settingsClientDefsPath =
    path.join(moduleRoot, 'Resources/metadata/clientDefs/ElevateRmSettings.json');
const settingsClientDefs = JSON.parse(await readFile(settingsClientDefsPath, 'utf8'));
for (const flag of ['createDisabled', 'editDisabled', 'removeDisabled']) {
    if (settingsClientDefs[flag] !== true) {
        failures.push(
            `${path.relative(root, settingsClientDefsPath)}: '${flag}' must be true for singleton settings`
        );
    }
}

const clientMetadataPath =
    'src/files/custom/Espo/Modules/ElevateResourceManagement/Resources/metadata/app/client.json';
try {
    const clientMetadata = JSON.parse(await readFile(path.join(root, clientMetadataPath), 'utf8'));
    const bundleInit = 'client/custom/modules/elevate-resource-management/lib/init.js';

    if (!Array.isArray(clientMetadata.scriptList) ||
        !clientMetadata.scriptList.includes('__APPEND__') ||
        !clientMetadata.scriptList.includes(bundleInit)) {
        failures.push(`${clientMetadataPath}: production bundle init script is not registered`);
    }
} catch (error) {
    failures.push(`${clientMetadataPath}: could not validate bundle registration: ${error.message}`);
}

const clientModuleRoot =
    path.join(root, 'src/files/client/custom/modules/elevate-resource-management/src');
for (const file of (await walk(clientModuleRoot)).filter(file => file.endsWith('.js'))) {
    const source = await readFile(file, 'utf8');

    if (/^\s*define\s*\(/.test(source)) {
        failures.push(
            `${path.relative(root, file)}: legacy AMD source would produce an anonymous bundled module`
        );
    }
}

const jsonArrayView = 'elevate-resource-management:views/fields/json-array';
const supportedJsonArrayViews = [
    jsonArrayView,
    'elevate-resource-management:views/fields/target-status-list',
    'elevate-resource-management:views/fields/default-work-blocks',
];
for (const file of (await walk(entityDefsRoot)).filter(file => file.endsWith('.json'))) {
    const definition = JSON.parse(await readFile(file, 'utf8'));

    for (const [field, fieldDefinition] of Object.entries(definition.fields ?? {})) {
        if (fieldDefinition.type === 'jsonArray' &&
            !supportedJsonArrayViews.includes(fieldDefinition.view)) {
            failures.push(
                `${path.relative(root, file)}: jsonArray field '${field}' must use a supported extension view`
            );
        }
    }
}

const instanceDefsPath = path.join(entityDefsRoot, 'ElevateRmInstance.json');
const instanceDefs = JSON.parse(await readFile(instanceDefsPath, 'utf8'));
const expectedInstanceViews = {
    targetEntityType: 'elevate-resource-management:views/fields/target-entity-type',
    identifierField: 'elevate-resource-management:views/fields/target-field',
    nameField: 'elevate-resource-management:views/fields/target-field',
    statusField: 'elevate-resource-management:views/fields/target-field',
    resourceField: 'elevate-resource-management:views/fields/target-field',
    accountField: 'elevate-resource-management:views/fields/target-field',
    contactField: 'elevate-resource-management:views/fields/target-field',
    inProgressStatus: 'elevate-resource-management:views/fields/target-status',
    completedStatusList: 'elevate-resource-management:views/fields/target-status-list',
    addTimeLogsTargetStatus: 'elevate-resource-management:views/fields/target-status',
    readyForBillingTargetStatus: 'elevate-resource-management:views/fields/target-status',
    invoicedTargetStatus: 'elevate-resource-management:views/fields/target-status',
};
const expectedMappingKinds = {
    identifierField: 'scalar',
    nameField: 'scalar',
    statusField: 'status',
    resourceField: 'resource',
    accountField: 'account',
    contactField: 'contact',
};
for (const [field, view] of Object.entries(expectedInstanceViews)) {
    if (instanceDefs.fields?.[field]?.view !== view) {
        failures.push(
            `${path.relative(root, instanceDefsPath)}: guided Instance field '${field}' must use '${view}'`
        );
    }
}
for (const [field, mappingKind] of Object.entries(expectedMappingKinds)) {
    if (instanceDefs.fields?.[field]?.mappingKind !== mappingKind) {
        failures.push(
            `${path.relative(root, instanceDefsPath)}: guided Instance field '${field}' must declare mappingKind '${mappingKind}'`
        );
    }
}

const scopeRoot = path.join(moduleRoot, 'Resources/metadata/scopes');
for (const file of (await walk(scopeRoot)).filter(file => file.endsWith('.json'))) {
    const scope = path.basename(file, '.json');
    const definition = JSON.parse(await readFile(file, 'utf8'));

    if (definition.entity !== true) {
        continue;
    }

    const controllerPath = path.join(moduleRoot, 'Controllers', `${scope}.php`);

    try {
        const controller = await readFile(controllerPath, 'utf8');

        if (!controller.includes(`class ${scope} extends Record`)) {
            failures.push(
                `${path.relative(root, controllerPath)}: entity controller must extend the generic Record controller`
            );
        }
    } catch {
        failures.push(
            `${path.relative(root, controllerPath)}: controller required for entity API scope '${scope}'`
        );
    }

    const clientDefsPath =
        path.join(moduleRoot, 'Resources/metadata/clientDefs', `${scope}.json`);

    try {
        const clientDefs = JSON.parse(await readFile(clientDefsPath, 'utf8'));

        if (clientDefs.controller !== 'controllers/record') {
            failures.push(
                `${path.relative(root, clientDefsPath)}: entity client controller must be 'controllers/record'`
            );
        }
    } catch {
        failures.push(
            `${path.relative(root, clientDefsPath)}: generic client controller mapping required for entity scope '${scope}'`
        );
    }
}

const php = spawnSync('php', ['--version'], {encoding: 'utf8'});
if (php.status === 0) {
    for (const file of (await walk(path.join(root, 'src'))).filter(file => file.endsWith('.php'))) {
        const lint = spawnSync('php', ['-l', file], {encoding: 'utf8'});
        if (lint.status !== 0) failures.push(`${path.relative(root, file)}: ${lint.stderr || lint.stdout}`);
    }
} else {
    console.warn('PHP is unavailable; PHP syntax validation was skipped.');
}

if (failures.length) {
    console.error(failures.join('\n'));
    process.exit(1);
}
console.log('Extension structure, placeholders and JSON metadata are valid.');
