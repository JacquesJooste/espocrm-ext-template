import View from 'view';
import {fetchAllRecords} from
    'elevate-resource-management:utils/fetch-all-records';
import {reportEntry, reportEntryText} from
    'elevate-resource-management:utils/report-entry';

export default class extends View {
    templateContent = `
        <div class="elevate-rm-workspace">
          <header class="page-header">
            <div><h3>{{translate 'timeManagement' scope='ElevateResourceManagement' category='labels'}}</h3><p class="text-muted">{{translate 'workspaceSummary' scope='ElevateResourceManagement' category='messages'}}</p></div>
            {{#if showInstancePicker}}<div class="elevate-rm-instance-picker"><label>{{translate 'instance' scope='ElevateResourceManagement' category='labels'}}</label>
              <select class="form-control" data-name="instanceId">
                <option value="">Select an instance</option>
                {{#each instances}}<option value="{{id}}">{{name}}</option>{{/each}}
              </select>
              <a class="btn btn-default" href="#ElevateRmInstance/create">{{translate 'createInstance' scope='ElevateResourceManagement' category='labels'}}</a>
            </div>{{/if}}
          </header>
          <nav class="elevate-rm-tabs" aria-label="Time management sections">
            {{#each tabs}}<a href="#ElevateResourceManagement/{{key}}" class="btn {{#if active}}btn-primary{{else}}btn-default{{/if}}">{{label}}</a>{{/each}}
          </nav>
          <section class="panel panel-default"><div class="panel-body elevate-rm-content">
            {{{content}}}
          </div></section>
        </div>`;

    setup() {
        const aliases = {
            capacity: 'planning',
            'work-blocks': 'library',
            settings: 'setup',
        };
        this.tab = aliases[this.options.tab] || this.options.tab || 'my-work';
        this.instances = [];
        this.selectedInstanceId = localStorage.getItem('elevateRmInstanceId') || '';
        this.wait(this.load());
        this.on('after:render', () => this.afterWorkspaceRender());
        this.on('remove', () => {
            if (this.capacityChart) this.capacityChart.destroy();
            this.gantt = null;
        });
    }

    async load() {
        this.permissions = await Espo.Ajax.getRequest('ElevateResourceManagement/permissions');
        const allowedTabs = this.allowedTabs().map(item => item[0]);
        if (!allowedTabs.includes(this.tab)) {
            this.tab = 'my-work';
        }
        if (this.permissions.manager) {
            this.instances = await fetchAllRecords('ElevateRmInstance', {
                where: [{type: 'notEquals', attribute: 'status', value: 'Archived'}],
                orderBy: 'name',
            });
            if (!this.selectedInstanceId && this.instances.length) {
                this.selectedInstanceId = this.instances[0].id;
            }
        }
        await this.loadContent();
    }

    async loadContent() {
        this.content = this.emptyMessage();
        try {
            if (this.tab === 'my-work') {
                const data = await Espo.Ajax.getRequest('ElevateResourceManagement/my-work');
                this.myWorkData = data;
                this.content = this.myWork(data);
                return;
            }

            if (this.tab === 'setup') {
                const [settings, users] = await Promise.all([
                    Espo.Ajax.getRequest('ElevateResourceManagement/settings'),
                    this.loadUsers(),
                ]);
                this.settingsData = settings;
                this.users = users;
                this.content = this.setupContent(settings, users);
                return;
            }

            if (!this.selectedInstanceId) {
                return;
            }

            if (this.tab === 'planning') {
                await this.loadCapacityLibraries();
                const from = new Date();
                const to = new Date(from.getTime() + 28 * 86400000);
                const data = await Espo.Ajax.getRequest('ElevateResourceManagement/capacity', {
                    instanceId: this.selectedInstanceId,
                    from: from.toISOString().slice(0, 19).replace('T', ' '),
                    to: to.toISOString().slice(0, 19).replace('T', ' '),
                });
                this.capacityData = data;
                this.content = this.capacityTable(data);
            } else if (this.tab === 'reporting') {
                if (!this.users) this.users = await this.loadUsers();
                const report = await this.loadReport();
                this.reportData = report;
                this.content = `${this.reportFilters()}<div class="elevate-rm-kpis">
                    <div><span>Entries</span><strong>${report.summary.entryCount}</strong></div>
                    <div><span>Elapsed</span><strong>${this.duration(report.summary.elapsedSeconds)}</strong></div>
                    <div><span>Labour</span><strong>${this.duration(report.summary.labourSeconds)}</strong></div>
                    <div><span>Resources</span><strong>${report.summary.resourceCount}</strong></div>
                </div>${this.entriesReport(report.items)}`;
            } else if (this.tab === 'library') {
                const [workItems, workBlocks] = await Promise.all([
                    fetchAllRecords('ElevateRmWorkItem', {
                        orderBy: 'name',
                        select: 'id,name,defaultEstimateSeconds,active',
                    }),
                    fetchAllRecords('ElevateRmWorkBlockTemplate', {
                        where: [{
                            type: 'equals',
                            attribute: 'instanceId',
                            value: this.selectedInstanceId,
                        }],
                        orderBy: 'defaultOrder',
                    }),
                ]);
                this.workItems = workItems;
                this.workBlocks = workBlocks;
                this.content = this.libraryContent();
            } else if (this.tab === 'billing') {
                const packages = await Espo.Ajax.getRequest(
                    `ElevateResourceManagement/billing-queue/${this.selectedInstanceId}`
                );
                this.billingPackages = packages.items || [];
                this.content = this.billingContent();
            }
        } catch (error) {
            this.content = `<div class="alert alert-warning">${this.escape(
                error?.message || 'This section is not available for your role.'
            )}</div>`;
        }
    }

    async loadUsers() {
        return fetchAllRecords('User', {
            where: [{type: 'and', value: [
                {type: 'equals', attribute: 'isActive', value: true},
                {type: 'in', attribute: 'type', value: ['regular', 'admin']},
            ]}],
            orderBy: 'name',
            select: 'id,name,type,isActive',
        });
    }

    afterWorkspaceRender() {
        this.$el.find('[data-name="instanceId"]').val(this.selectedInstanceId).on('change', event => {
            this.selectedInstanceId = event.currentTarget.value;
            localStorage.setItem('elevateRmInstanceId', this.selectedInstanceId);
            this.loadContent().then(() => this.reRender());
        });
        this.$el.find('[data-action="save-settings"]').on('click', () => this.saveSettings());
        this.$el.find('[data-action="create-work-block"]').on('click', () => this.openWorkBlockEditor());
        this.$el.find('[data-action="edit-work-block"]').on('click', event => {
            this.openWorkBlockEditor(event.currentTarget.dataset.id);
        });
        this.$el.find('[data-action="delete-instance"]').on('click', event => {
            this.deleteInstance(event.currentTarget.dataset.id);
        });
        this.$el.find('[data-action="apply-report-filters"]').on('click', () => {
            this.reportFiltersState = {
                from: this.$el.find('[data-name="reportFrom"]').val(),
                to: this.$el.find('[data-name="reportTo"]').val(),
                userId: this.$el.find('[data-name="reportUserId"]').val(),
            };
            this.loadContent().then(() => this.reRender());
        });
        this.$el.find('[data-action="copy-report"]').on('click', () => this.copyReport());
        this.$el.find('[data-action="download-report"]').on('click', () => this.downloadReport());
        this.renderCapacityChart();
        this.renderGantt();
    }

    data() {
        const defs = this.allowedTabs();
        return {
            instances: this.instances,
            content: this.content,
            showInstancePicker: Boolean(this.permissions?.manager),
            tabs: defs.map(([key, label]) => ({key, label, active: key === this.tab})),
        };
    }

    allowedTabs() {
        const tabs = [['my-work', this.translate('myWork', 'labels', 'ElevateResourceManagement')]];
        if (this.permissions?.manager) {
            tabs.push(
                ['planning', this.translate('planning', 'labels', 'ElevateResourceManagement')],
                ['reporting', this.translate('reporting', 'labels', 'ElevateResourceManagement')]
            );
        }
        if (this.permissions?.billingManager) {
            tabs.push(['billing', this.translate('billing', 'labels', 'ElevateResourceManagement')]);
        }
        if (this.permissions?.manager) {
            tabs.push(
                ['library', this.translate('library', 'labels', 'ElevateResourceManagement')],
                ['setup', this.translate('setup', 'labels', 'ElevateResourceManagement')]
            );
        }
        return tabs;
    }

    myWork(data) {
        const active = data.activeSession
            ? `<div class="alert alert-success elevate-rm-active-timer">
                <span><strong>Timer running</strong><br>${this.escape(data.activeSession.name)} · started ${this.escape(data.activeSession.startedAt)}</span>
                ${data.activeTarget ? `<a class="btn btn-success" href="#${this.escape(data.activeTarget.entityType)}/view/${this.escape(data.activeTarget.id)}">Open Active Work</a>` : '<span class="fas fa-stopwatch fa-2x" aria-hidden="true"></span>'}
              </div>`
            : `<div class="alert alert-info">No timer is currently running. Open an assigned target and choose <strong>Log Time</strong>.</div>`;
        const rows = (data.items || []).map(item => `<tr>
            <td><a href="#${this.escape(item.targetType)}/view/${this.escape(item.targetId)}">${this.escape(item.targetIdentifier || item.targetName)}</a></td>
            <td>${this.escape(item.nameSnapshot)}</td>
            <td>${this.escape(item.dateStart)}</td>
            <td>${this.duration(item.estimatedSeconds)}</td>
            <td><span class="label label-${item.status === 'In Progress' ? 'warning' : 'default'}">${this.escape(item.status)}</span></td>
        </tr>`).join('');
        return `${active}<div class="elevate-rm-section-heading"><h4>Upcoming Work Items</h4></div>
            <div class="table-responsive"><table class="table table-striped">
            <thead><tr><th>Target</th><th>Work Item</th><th>Scheduled</th><th>Estimate</th><th>Status</th></tr></thead>
            <tbody>${rows || '<tr><td colspan="5" class="text-muted">No upcoming Work Items are assigned to you.</td></tr>'}</tbody>
            </table></div>`;
    }

    libraryContent() {
        const selectedInstance = this.instances.find(item => item.id === this.selectedInstanceId);
        const finish = selectedInstance?.targetEntityType
            ? `<a class="btn btn-success" href="#${this.escape(selectedInstance.targetEntityType)}">Finish and Open ${this.escape(selectedInstance.targetEntityType)} Records</a>`
            : '';
        const itemRows = this.workItems.map(item => `<tr>
            <td><a href="#ElevateRmWorkItem/view/${this.escape(item.id)}">${this.escape(item.name)}</a></td>
            <td>${this.duration(item.defaultEstimateSeconds)}</td>
            <td>${item.active ? 'Active' : 'Inactive'}</td>
        </tr>`).join('');
        const blockRows = this.workBlocks.map(block => `<tr>
            <td><button type="button" class="btn btn-link" data-action="edit-work-block" data-id="${this.escape(block.id)}">${this.escape(block.name)}</button></td>
            <td>${this.duration(block.estimatedSeconds)}</td>
            <td>${this.escape(block.milestoneKind)}</td>
            <td>${block.isDefault ? `#${this.escape(block.defaultOrder)}` : '—'}</td>
            <td>${block.active ? 'Active' : 'Inactive'}</td>
        </tr>`).join('');
        return `<div class="alert alert-info elevate-rm-library-guide">
            <div><strong>Step 2 of 3 · Build the default work plan</strong><p>Create reusable Work Items, group them into ordered Work Blocks, then continue to the configured target.</p></div>
            ${finish}
        </div><div class="row">
            <section class="col-md-5">
                <div class="elevate-rm-section-heading"><div><h4>Work Items</h4><p class="text-muted">Reusable activities with a canonical description and estimate.</p></div>
                <a class="btn btn-default" href="#ElevateRmWorkItem/create?rootUrl=${encodeURIComponent('#ElevateResourceManagement/library')}&returnUrl=${encodeURIComponent('#ElevateResourceManagement/library')}">Create Work Item</a></div>
                <div class="table-responsive"><table class="table table-striped"><thead><tr><th>Item</th><th>Estimate</th><th>Status</th></tr></thead><tbody>${itemRows || '<tr><td colspan="3" class="text-muted">No Work Items yet.</td></tr>'}</tbody></table></div>
            </section>
            <section class="col-md-7">
                <div class="elevate-rm-section-heading"><div><h4>Work Blocks</h4><p class="text-muted">Ordered groups of Work Items used for planning and progress.</p></div>
                <button type="button" class="btn btn-primary" data-action="create-work-block">Create Work Block</button></div>
                <div class="table-responsive"><table class="table table-striped"><thead><tr><th>Work Block</th><th>Total</th><th>Milestone</th><th>Default Order</th><th>Status</th></tr></thead><tbody>${blockRows || '<tr><td colspan="5" class="text-muted">No Work Blocks yet.</td></tr>'}</tbody></table></div>
            </section>
        </div>`;
    }

    setupContent(settings, users) {
        const managerUsers = [...users];
        [
            ['operationsManagerId', 'operationsManagerName'],
            ['billingAdministratorId', 'billingAdministratorName'],
        ].forEach(([idField, nameField]) => {
            const id = settings[idField];
            if (id && !managerUsers.some(user => user.id === id)) {
                managerUsers.push({
                    id,
                    name: settings[nameField] || 'Configured user',
                });
            }
        });
        const options = selected => managerUsers.map(user =>
            `<option value="${this.escape(user.id)}" ${user.id === selected ? 'selected' : ''}>${this.escape(user.name)}</option>`
        ).join('');
        const instanceRows = this.instances.map(instance => `<tr>
            <td><a href="#ElevateRmInstance/view/${this.escape(instance.id)}">${this.escape(instance.name)}</a></td>
            <td>${this.escape(instance.mode)}</td><td>${this.escape(instance.targetEntityType)}</td>
            <td>${this.escape(instance.status)}</td>
            <td><button type="button" class="btn btn-danger btn-xs" data-action="delete-instance" data-id="${this.escape(instance.id)}">Delete</button></td>
        </tr>`).join('');
        return `<div class="row">
            <section class="col-md-6">
                <h4>Resource Management Settings</h4>
                <p class="text-muted">These persistent settings are stored once for the extension.</p>
                <div class="form-group"><label>Operations Manager</label><select class="form-control" data-name="operationsManagerId">${options(settings.operationsManagerId)}</select></div>
                <div class="form-group"><label>Billing Administrator</label><select class="form-control" data-name="billingAdministratorId">${options(settings.billingAdministratorId)}</select></div>
                <div class="checkbox"><label><input type="checkbox" data-name="autoMarkInvoicedOnExport" ${settings.autoMarkInvoicedOnExport ? 'checked' : ''}> Automatically mark invoiced after a successful export</label></div>
                <button type="button" class="btn btn-primary" data-action="save-settings">Save Settings</button>
            </section>
            <section class="col-md-6">
                <div class="elevate-rm-section-heading"><div><h4>Instances</h4><p class="text-muted">Connect Time Management to existing CRM entities.</p></div><a class="btn btn-default" href="#ElevateRmInstance/create">Create Instance</a></div>
                <div class="table-responsive"><table class="table table-striped"><thead><tr><th>Instance</th><th>Type</th><th>Target</th><th>Status</th><th></th></tr></thead><tbody>${instanceRows || '<tr><td colspan="5" class="text-muted">No Instances configured.</td></tr>'}</tbody></table></div>
            </section>
        </div>`;
    }

    async deleteInstance(id) {
        const instance = this.instances.find(item => item.id === id);
        const name = instance?.name || 'this Instance';
        if (!window.confirm(`Delete ${name}? Instances with planning or time history cannot be deleted.`)) {
            return;
        }

        const $button = this.$el.find(`[data-action="delete-instance"][data-id="${this.escape(id)}"]`);
        $button.prop('disabled', true);
        try {
            await Espo.Ajax.deleteRequest(`ElevateRmInstance/${id}`);
            if (this.selectedInstanceId === id) {
                localStorage.removeItem('elevateRmInstanceId');
                this.selectedInstanceId = '';
            }
            await this.load();
            Espo.Ui.success('Instance deleted.');
            this.reRender();
        } catch (error) {
            Espo.Ui.error(error?.message || 'The Instance could not be deleted.');
            $button.prop('disabled', false);
        }
    }

    async saveSettings() {
        const $button = this.$el.find('[data-action="save-settings"]');
        $button.prop('disabled', true);
        try {
            await Espo.Ajax.putRequest('ElevateResourceManagement/settings', {
                operationsManagerId: this.$el.find('[data-name="operationsManagerId"]').val(),
                billingAdministratorId: this.$el.find('[data-name="billingAdministratorId"]').val(),
                autoMarkInvoicedOnExport: this.$el.find('[data-name="autoMarkInvoicedOnExport"]').is(':checked'),
            });
            Espo.Ui.success(this.translate(
                'settingsSaved',
                'messages',
                'ElevateResourceManagement'
            ));
        } catch (error) {
            Espo.Ui.error(error?.message || this.translate(
                'settingsSaveFailed',
                'messages',
                'ElevateResourceManagement'
            ));
        } finally {
            $button.prop('disabled', false);
        }
    }

    openWorkBlockEditor(workBlockId = null) {
        this.createView('workBlockEditor', 'elevate-resource-management:views/work-block-editor', {
            workBlockId,
            instanceId: this.selectedInstanceId,
        }).then(view => {
            this.listenToOnce(view, 'completed', () => {
                this.loadContent().then(() => this.reRender());
            });
            view.render();
        });
    }

    billingContent() {
        const rows = (this.billingPackages || []).map(item => `<tr>
            <td><a href="#${this.escape(item.targetType)}/view/${this.escape(item.targetId)}">${this.escape(item.targetIdentifier || item.targetName)}</a></td>
            <td><span class="label label-default">${this.escape(item.lifecycle)}</span></td>
            <td>${this.escape(item.completionPercent)}%</td>
            <td>${this.duration(item.totalEstimateSeconds)}</td>
            <td>${this.escape(item.plannedStart)}</td>
        </tr>`).join('');
        return `<div class="elevate-rm-queue-grid">
            <div><span class="fas fa-exclamation-circle"></span><h4>Add Time Logs</h4><p>Completed targets with Work Items still missing time.</p></div>
            <div><span class="fas fa-check-circle"></span><h4>Ready for Billing</h4><p>Complete target work ready for review and export.</p></div>
            <div><span class="fas fa-file-invoice-dollar"></span><h4>Invoiced</h4><p>Locked, reproducible billing snapshots.</p></div>
        </div><div class="table-responsive"><table class="table table-striped">
            <thead><tr><th>Target</th><th>Billing Stage</th><th>Progress</th><th>Estimate</th><th>Planned</th></tr></thead>
            <tbody>${rows || '<tr><td colspan="5" class="text-muted">The billing queue is empty.</td></tr>'}</tbody>
        </table></div>`;
    }

    async loadCapacityLibraries() {
        if (this.ChartLibrary && this.GanttLibrary) return;
        [this.ChartLibrary, this.GanttLibrary] = await Promise.all([
            Espo.loader.requirePromise('lib!elevate-rm-chart'),
            Espo.loader.requirePromise('lib!elevate-rm-gantt'),
        ]);
    }

    capacityTable(data) {
        const rows = (data.items || []).map(item =>
            `<tr><td><a href="#ElevateRmScheduledBlock/view/${this.escape(item.id)}">${this.escape(item.name)}</a></td><td>${this.escape(item.dateStart)}</td><td>${this.escape(item.dateEnd)}</td><td>${this.escape((item.userIds || []).join(', '))}</td><td>${this.escape(item.status)}</td></tr>`
        ).join('');
        const advice = (data.advice || []).map(item => `<li>${this.escape(item.message)} (${item.utilizationPercent}%)</li>`).join('');
        return `<div class="elevate-rm-capacity"><div class="elevate-rm-section-heading"><div><h4>Upcoming 28 days</h4><p class="text-muted">Scheduled allocation compared with bookable capacity.</p></div><a class="btn btn-default" href="#Calendar">Open Calendar</a></div>
            <div class="elevate-rm-capacity-chart"><canvas data-name="capacityChart" height="90"></canvas></div>
            <h4>Schedule</h4><div class="elevate-rm-gantt"><svg data-name="gantt"></svg></div>
            <div class="table-responsive"><table class="table table-striped"><thead><tr><th>Work Block</th><th>Start</th><th>End</th><th>Resources</th><th>Status</th></tr></thead><tbody>${rows}</tbody></table></div>
            ${advice ? `<div class="alert alert-info"><strong>Planning advice</strong><ul>${advice}</ul></div>` : ''}</div>`;
    }

    async loadReport() {
        const filters = this.reportFiltersState || {};
        const payload = {instanceId: this.selectedInstanceId};
        if (filters.from) payload.from = `${filters.from} 00:00:00`;
        if (filters.to) payload.to = `${filters.to} 23:59:59`;
        if (filters.userId) payload.userId = filters.userId;

        return Espo.Ajax.postRequest('ElevateResourceManagement/reports', payload);
    }

    reportFilters() {
        const filters = this.reportFiltersState || {};
        const users = (this.users || []).map(user =>
            `<option value="${this.escape(user.id)}" ${filters.userId === user.id ? 'selected' : ''}>${this.escape(user.name)}</option>`
        ).join('');
        return `<div class="elevate-rm-report-toolbar">
            <div class="form-group"><label>From</label><input class="form-control" type="date" data-name="reportFrom" value="${this.escape(filters.from || '')}"></div>
            <div class="form-group"><label>To</label><input class="form-control" type="date" data-name="reportTo" value="${this.escape(filters.to || '')}"></div>
            <div class="form-group"><label>Team member</label><select class="form-control" data-name="reportUserId"><option value="">All team members</option>${users}</select></div>
            <button class="btn btn-primary" type="button" data-action="apply-report-filters">Apply</button>
            <div class="elevate-rm-report-actions"><button class="btn btn-default" type="button" data-action="copy-report">Copy report</button><button class="btn btn-default" type="button" data-action="download-report">Download CSV</button></div>
        </div>`;
    }

    entriesReport(items) {
        const cards = (items || []).map(item => {
            const entry = reportEntry(item);
            const note = entry.flagged || entry.note
                ? `<div class="elevate-rm-report-note ${entry.flagged ? 'is-flagged' : ''}"><strong>${entry.flagged ? 'Flagged' : 'Note'}</strong>${entry.note ? ` · ${this.escape(entry.note)}` : ''}</div>`
                : '';
            return `<article class="elevate-rm-report-entry">
                <div class="elevate-rm-report-meta"><strong>${this.escape(entry.date)}</strong><span>${this.escape(entry.start)} – ${this.escape(entry.finish)}</span><span>Team of ${entry.teamCount}: ${this.escape(entry.teamNames)}</span></div>
                <div class="elevate-rm-report-target">${this.escape(item.targetIdentifier || item.targetName)} · ${this.escape(item.blockName)}</div>
                <div class="elevate-rm-report-content">${this.escape(entry.content)}</div>${note}
            </article>`;
        }).join('');
        return `<div class="elevate-rm-report-list">${cards || '<div class="text-muted">No time entries match these filters.</div>'}</div>`;
    }

    async copyReport() {
        const text = (this.reportData?.items || []).map(reportEntryText).join('\n\n---\n\n');
        try {
            await navigator.clipboard.writeText(text);
            Espo.Ui.success('Report copied.');
        } catch {
            Espo.Ui.error('The report could not be copied by this browser.');
        }
    }

    downloadReport() {
        const blob = new Blob([this.reportData?.csv || ''], {type: 'text/csv;charset=utf-8'});
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = 'time-report.csv';
        link.click();
        URL.revokeObjectURL(url);
    }

    emptyMessage() {
        return '<p class="text-muted">Select or create an Instance to begin.</p>';
    }

    duration(seconds) {
        const value = Number(seconds || 0);
        return `${Math.floor(value / 3600)}h ${String(Math.floor(value % 3600 / 60)).padStart(2, '0')}m`;
    }

    escape(value) {
        return Handlebars.Utils.escapeExpression(String(value == null ? '' : value));
    }

    renderCapacityChart() {
        if (this.tab !== 'planning' || !this.capacityData) return;
        if (this.capacityChart) this.capacityChart.destroy();
        const Chart = this.ChartLibrary.Chart || this.ChartLibrary;
        const allocated = this.capacityData.allocatedSecondsByUser || {};
        const bookable = this.capacityData.bookableSecondsByUser || {};
        const labels = Object.keys({...bookable, ...allocated});
        const canvas = this.$el.find('[data-name="capacityChart"]')[0];
        if (!canvas || !labels.length) return;
        this.capacityChart = new Chart(canvas, {
            type: 'bar',
            data: {
                labels,
                datasets: [
                    {label: 'Allocated hours', data: labels.map(id => (allocated[id] || 0) / 3600), backgroundColor: '#4f6bed'},
                    {label: 'Bookable hours', data: labels.map(id => (bookable[id] || 0) / 3600), backgroundColor: '#a8b5e8'},
                ],
            },
            options: {responsive: true, maintainAspectRatio: false, scales: {y: {beginAtZero: true}}},
        });
    }

    renderGantt() {
        if (this.tab !== 'planning' || !this.capacityData || !(this.capacityData.items || []).length) return;
        const Gantt = this.GanttLibrary.default || this.GanttLibrary;
        const element = this.$el.find('[data-name="gantt"]')[0];
        if (!element || typeof Gantt !== 'function') return;
        const tasks = this.capacityData.items.map(item => ({
            id: item.id,
            name: item.name,
            start: item.dateStart.slice(0, 10),
            end: item.dateEnd.slice(0, 10),
            progress: item.status === 'Completed' ? 100 : 0,
            dependencies: '',
            revision: item.revision,
        }));
        this.gantt = new Gantt(element, tasks, {
            view_mode: 'Week',
            readonly_progress: true,
            on_date_change: async (task, start, end) => {
                try {
                    const result = await Espo.Ajax.putRequest(`ElevateResourceManagement/scheduled-blocks/${task.id}`, {
                        dateStart: start.toISOString().slice(0, 19).replace('T', ' '),
                        dateEnd: end.toISOString().slice(0, 19).replace('T', ' '),
                        revision: task.revision,
                    });
                    task.revision = result.block.revision;
                    if ((result.warnings || []).length) {
                        Espo.Ui.warning(result.warnings.map(item => item.message).join(' '));
                    }
                } catch (error) {
                    Espo.Ui.error(error?.message || 'The schedule change was rejected.');
                    await this.loadContent();
                    this.reRender();
                }
            },
        });
    }
}
