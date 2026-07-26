import View from 'view';

export default class extends View {
    templateContent = `
        <div class="elevate-rm-workspace">
          <header class="page-header"><h3>Elevate Resource Management</h3>
            <div class="elevate-rm-instance-picker"><label>Instance</label>
              <select class="form-control" data-name="instanceId">
                <option value="">Select an instance</option>
                {{#each instances}}<option value="{{id}}">{{name}}</option>{{/each}}
              </select>
              <a class="btn btn-default" href="#ElevateRmInstance/create">Add Instance</a>
            </div>
          </header>
          <nav class="elevate-rm-tabs" aria-label="Time management sections">
            {{#each tabs}}<a href="#ElevateResourceManagement/{{key}}" class="btn {{#if active}}btn-primary{{else}}btn-default{{/if}}">{{label}}</a>{{/each}}
          </nav>
          <section class="panel panel-default"><div class="panel-body elevate-rm-content">
            {{{content}}}
          </div></section>
        </div>`;

    setup() {
        this.tab = this.options.tab || 'capacity';
        this.instances = [];
        this.selectedInstanceId = localStorage.getItem('elevateRmInstanceId') || '';
        this.wait(this.load());
        this.on('after:render', () => {
            this.$el.find('[data-name="instanceId"]').val(this.selectedInstanceId).on('change', event => {
                this.selectedInstanceId = event.currentTarget.value;
                localStorage.setItem('elevateRmInstanceId', this.selectedInstanceId);
                this.loadContent().then(() => this.reRender());
            });
            this.renderCapacityChart();
            this.renderGantt();
        });
        this.on('remove', () => {
            if (this.capacityChart) this.capacityChart.destroy();
            this.gantt = null;
        });
    }

    async load() {
        const response = await Espo.Ajax.getRequest('ElevateRmInstance', {
            where: [{type: 'notEquals', attribute: 'status', value: 'Archived'}],
            maxSize: 200,
            orderBy: 'name',
        });
        this.instances = response.list || [];
        if (!this.selectedInstanceId && this.instances.length) {
            this.selectedInstanceId = this.instances[0].id;
        }
        await this.loadContent();
    }

    async loadContent() {
        this.content = this.emptyMessage();
        if (!this.selectedInstanceId) {
            return;
        }

        if (this.tab === 'capacity') {
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
            const report = await Espo.Ajax.postRequest('ElevateResourceManagement/reports', {});
            this.content = `<div class="elevate-rm-kpis"><strong>${report.summary.entryCount}</strong> entries ·
                <strong>${this.duration(report.summary.elapsedSeconds)}</strong> elapsed ·
                <strong>${this.duration(report.summary.labourSeconds)}</strong> labour</div>
                ${this.entriesTable(report.items)}`;
        } else if (this.tab === 'work-blocks') {
            this.content = '<p>Manage reusable activity and estimate templates.</p><a class="btn btn-primary" href="#ElevateRmWorkBlockTemplate">Open Work Blocks</a>';
        } else if (this.tab === 'settings') {
            this.content = '<p>Configure managers, instances, target mappings, and conflict policies.</p><a class="btn btn-primary" href="#ElevateRmSettings">Global Settings</a> <a class="btn btn-default" href="#ElevateRmInstance">Instances</a>';
        } else if (this.tab === 'billing') {
            this.content = '<div class="row"><div class="col-sm-4"><h4>Add Time Logs</h4><p>Completed targets with missing entries.</p></div><div class="col-sm-4"><h4>Ready for Billing</h4><p>Complete packages ready for review.</p></div><div class="col-sm-4"><h4>Invoiced</h4><p>Locked, reproducible snapshots.</p></div></div><a class="btn btn-primary" href="#ElevateRmWorkPackage">Open Billing Queue</a>';
        }
    }

    async loadCapacityLibraries() {
        if (this.ChartLibrary && this.GanttLibrary) {
            return;
        }

        [this.ChartLibrary, this.GanttLibrary] = await Promise.all([
            Espo.loader.requirePromise('lib!elevate-rm-chart'),
            Espo.loader.requirePromise('lib!elevate-rm-gantt'),
        ]);
    }

    data() {
        const defs = [
            ['capacity', 'Capacity'], ['reporting', 'Reporting'], ['work-blocks', 'Work Blocks'],
            ['settings', 'Settings'], ['billing', 'Billing'],
        ];
        return {
            instances: this.instances,
            content: this.content,
            tabs: defs.map(([key, label]) => ({key, label, active: key === this.tab})),
        };
    }

    capacityTable(data) {
        const rows = (data.items || []).map(item =>
            `<tr><td><a href="#ElevateRmScheduledBlock/view/${this.escape(item.id)}">${this.escape(item.name)}</a></td><td>${this.escape(item.dateStart)}</td><td>${this.escape(item.dateEnd)}</td><td>${this.escape((item.userIds || []).join(', '))}</td><td>${this.escape(item.status)}</td></tr>`
        ).join('');
        const advice = (data.advice || []).map(item => `<li>${this.escape(item.message)} (${item.utilizationPercent}%)</li>`).join('');
        const selected = this.instances.find(item => item.id === this.selectedInstanceId);
        const projectItems = selected && selected.mode === 'Project'
            ? (data.items || []).filter(item => item.milestoneKind && item.milestoneKind !== 'Normal')
                .map(item => `<li><strong>${this.escape(item.milestoneKind)}</strong> — ${this.escape(item.name)} (${this.escape(item.dateEnd)})</li>`).join('')
            : '';
        return `<div class="elevate-rm-capacity"><h4>Upcoming 28 days</h4>
            <div class="elevate-rm-capacity-chart"><canvas data-name="capacityChart" height="90"></canvas></div>
            <h4>Gantt</h4><div class="elevate-rm-gantt"><svg data-name="gantt"></svg></div>
            <table class="table table-striped"><thead><tr><th>Work Block</th><th>Start</th><th>End</th><th>Resources</th><th>Status</th></tr></thead><tbody>${rows}</tbody></table>
            ${projectItems ? `<div class="elevate-rm-roadmap"><h4>Project milestones and roadmap</h4><ul>${projectItems}</ul></div>` : ''}
            ${advice ? `<div class="alert alert-info"><strong>Planning advice</strong><ul>${advice}</ul></div>` : ''}</div>`;
    }

    entriesTable(items) {
        const rows = (items || []).map(item =>
            `<tr><td>${this.escape(item.name)}</td><td>${this.escape(item.dateStart)}</td><td>${this.duration(item.elapsedSeconds)}</td><td>${this.duration(item.labourSeconds)}</td><td>${this.escape((item.attendeeNames || []).join(', '))}</td></tr>`
        ).join('');
        return `<table class="table table-striped"><thead><tr><th>Entry</th><th>Start</th><th>Elapsed</th><th>Labour</th><th>Team</th></tr></thead><tbody>${rows}</tbody></table>`;
    }

    emptyMessage() {
        return '<p class="text-muted">Select or add an instance to begin.</p>';
    }

    duration(seconds) {
        const value = Number(seconds || 0);
        return `${Math.floor(value / 3600)}h ${Math.floor(value % 3600 / 60)}m`;
    }

    escape(value) {
        return Handlebars.Utils.escapeExpression(String(value == null ? '' : value));
    }

    renderCapacityChart() {
        if (this.tab !== 'capacity' || !this.capacityData) {
            return;
        }
        if (this.capacityChart) {
            this.capacityChart.destroy();
        }
        const Chart = this.ChartLibrary.Chart || this.ChartLibrary;
        const allocated = this.capacityData.allocatedSecondsByUser || {};
        const bookable = this.capacityData.bookableSecondsByUser || {};
        const labels = Object.keys({...bookable, ...allocated});
        const canvas = this.$el.find('[data-name="capacityChart"]')[0];
        if (!canvas || !labels.length) {
            return;
        }
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
        if (this.tab !== 'capacity' || !this.capacityData || !(this.capacityData.items || []).length) {
            return;
        }
        const Gantt = this.GanttLibrary.default || this.GanttLibrary;
        const element = this.$el.find('[data-name="gantt"]')[0];
        if (!element || typeof Gantt !== 'function') {
            return;
        }
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
                } catch (e) {
                    Espo.Ui.error((e && e.message) || 'The schedule change was rejected.');
                    await this.loadContent();
                    this.reRender();
                }
            },
        });
    }
}
