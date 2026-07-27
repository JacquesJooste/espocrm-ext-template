class TargetControls {
    constructor(view) {
        this.view = view;
        this.context = null;
        this.timerInterval = null;
    }

    process() {
        this.listenTo(this.view, 'after:render', () => this.load());
        this.listenTo(this.view, 'remove', () => this.stopElapsedClock());
    }

    async load() {
        const model = this.view.model;
        if (!model || !model.id || model.entityType.startsWith('ElevateRm')) {
            return;
        }

        try {
            this.context = await Espo.Ajax.getRequest(
                `ElevateResourceManagement/context/${encodeURIComponent(model.entityType)}/${encodeURIComponent(model.id)}`
            );
        } catch (e) {
            return;
        }

        const selector = this.view.$el.find('.record').first();
        if (!selector.length) {
            return;
        }

        selector.find('.elevate-rm-target-controls').remove();
        if (!this.context.eligible) {
            if (!['User', 'Account', 'Contact'].includes(model.entityType)) {
                return;
            }
            try {
                const rollup = await Espo.Ajax.getRequest(
                    `ElevateResourceManagement/rollups/${model.entityType}/${encodeURIComponent(model.id)}`
                );
                selector.prepend(this.renderRollup(rollup));
            } catch (e) {
                return;
            }
            return;
        }

        selector.prepend(this.renderControls());
        selector.find('.elevate-rm-target-controls [data-action]').on('click', event => {
            this.openAction(event.currentTarget.dataset.action);
        });
        this.startElapsedClock();
    }

    renderControls() {
        const summary = this.summary();
        const active = this.context.activeSession;
        const primaryAction = active
            ? 'stopTimer'
            : (this.context.package && summary.nextItem ? 'logTime' : 'workBlocks');
        const primaryLabel = active
            ? this.t('stopTimer')
            : this.t(primaryAction === 'logTime' ? 'logTime' : 'addWorkBlocks');
        const primaryStyle = active ? 'danger' : 'primary';
        const workBlocksButton = primaryAction === 'workBlocks' ? '' :
            `<button class="btn btn-default" data-action="workBlocks"><span class="fas fa-layer-group"></span> ${this.t('workBlocks')}</button>`;
        const timer = active
            ? `<div class="elevate-rm-timer-state">
                <span class="elevate-rm-pulse" aria-hidden="true"></span>
                <span><strong>${this.escape(active.name)}</strong><small>${this.escape((active.attendeeNames || []).join(', '))}</small></span>
                <strong data-name="elapsedTimer">${this.elapsed(active.startedAt)}</strong>
              </div>`
            : '';
        const next = summary.nextItem
            ? `<div><span>${this.t('nextWorkItem')}</span><strong>${this.escape(summary.nextItem.nameSnapshot)}</strong></div>`
            : `<div><span>${this.t('nextWorkItem')}</span><strong>${this.context.package ? 'All work complete' : 'Choose a Work Block'}</strong></div>`;
        const scheduled = summary.nextSchedule
            ? this.escape(summary.nextSchedule.dateStart)
            : 'Not scheduled';
        const resources = summary.nextSchedule?.userNames?.length
            ? this.escape(summary.nextSchedule.userNames.join(', '))
            : 'Unassigned';

        return `<section class="panel panel-default elevate-rm-target-controls" aria-label="Time management">
            <header class="panel-heading elevate-rm-summary-heading">
                <div><strong>${this.t('timeManagement')}</strong><small>${this.t('workItems')} · ${this.t('workBlocks')}</small></div>
                <span class="label label-${active ? 'success' : 'default'}">${active ? this.t('timerActive') : this.escape(this.context.package?.lifecycle || 'Not planned')}</span>
            </header>
            <div class="panel-body">
                ${timer}
                <div class="elevate-rm-summary-grid">
                    ${next}
                    <div><span>${this.t('scheduled')}</span><strong>${scheduled}</strong></div>
                    <div><span>${this.t('assignedResources')}</span><strong>${resources}</strong></div>
                    <div><span>${this.t('progress')}</span><strong>${summary.progress}%</strong><small>${this.duration(summary.elapsed)} ${this.t('elapsed').toLowerCase()} · ${this.duration(summary.labour)} ${this.t('labour').toLowerCase()}</small></div>
                </div>
                <div class="progress elevate-rm-progress" aria-label="${summary.progress}% complete">
                    <div class="progress-bar" style="width:${summary.progress}%"></div>
                </div>
                <div class="elevate-rm-actions">
                    <button class="btn btn-${primaryStyle} elevate-rm-primary-action" data-action="${primaryAction}">
                        <span class="fas fa-${active ? 'stop-circle' : primaryAction === 'logTime' ? 'stopwatch' : 'layer-group'}"></span> ${primaryLabel}
                    </button>
                    ${workBlocksButton}
                </div>
                ${this.renderHistory()}
            </div>
        </section>`;
    }

    renderHistory() {
        if (!this.context.package) return '';
        const blockRows = (this.context.workBlocks || []).map(block => `<tr>
            <td>${this.escape(block.name)}</td><td>${this.escape(block.status)}</td>
            <td>${this.escape(block.completionPercent)}%</td>
            <td>${this.duration(block.actualElapsedSeconds)}</td>
            <td>${this.duration(block.actualLabourSeconds)}</td>
        </tr>`).join('');
        const entryRows = (this.context.timeEntries || []).map(entry => `<tr>
            <td>${this.escape(entry.workItemName || 'Legacy entry')}</td>
            <td>${this.escape(entry.dateStart)}</td>
            <td>${this.duration(entry.elapsedSeconds)}</td>
            <td>${this.duration(entry.labourSeconds)}</td>
            <td>${this.escape((entry.attendeeNames || []).join(', '))}</td>
        </tr>`).join('');
        return `<details class="elevate-rm-history">
            <summary>${this.t('workHistory')}</summary>
            <h5>${this.t('workBlocks')}</h5>
            <div class="table-responsive"><table class="table table-striped"><thead><tr><th>${this.t('workBlock')}</th><th>${this.t('status')}</th><th>${this.t('progress')}</th><th>${this.t('elapsed')}</th><th>${this.t('labour')}</th></tr></thead><tbody>${blockRows}</tbody></table></div>
            <h5>${this.t('recentTimeEntries')}</h5>
            <div class="table-responsive"><table class="table table-striped"><thead><tr><th>${this.t('workItem')}</th><th>${this.t('start')}</th><th>${this.t('elapsed')}</th><th>${this.t('labour')}</th><th>${this.t('team')}</th></tr></thead><tbody>${entryRows || `<tr><td colspan="5" class="text-muted">${this.tm('noTimeLogged')}</td></tr>`}</tbody></table></div>
        </details>`;
    }

    summary() {
        const blocks = this.context.workBlocks || [];
        const unfinished = blocks.flatMap(block =>
            (block.items || [])
                .filter(item => !['Completed', 'Cancelled'].includes(item.status))
                .map(item => ({...item, blockSequence: Number(block.sequence || 0)}))
        ).sort((a, b) => a.blockSequence - b.blockSequence || Number(a.sequence || 0) - Number(b.sequence || 0));
        const nextItem = unfinished[0] || null;
        const schedules = blocks.flatMap(block => block.schedules || []);
        const nextSchedule = nextItem
            ? schedules.find(schedule => schedule.id === nextItem.scheduledBlockId) || null
            : null;
        const total = blocks.reduce((sum, block) => sum + Number(block.totalEstimateSeconds || 0), 0);
        const completed = blocks.reduce((sum, block) =>
            sum + Number(block.totalEstimateSeconds || 0) * Number(block.completionPercent || 0) / 100, 0);

        return {
            nextItem,
            nextSchedule,
            progress: total ? Math.min(100, Math.round(completed / total * 100)) : 0,
            elapsed: blocks.reduce((sum, block) => sum + Number(block.actualElapsedSeconds || 0), 0),
            labour: blocks.reduce((sum, block) => sum + Number(block.actualLabourSeconds || 0), 0),
        };
    }

    renderRollup(rollup) {
        if (rollup.kind === 'User') {
            const rows = (rollup.upcoming || []).map(item => `<tr>
                <td><a href="#${this.escape(item.targetType)}/view/${this.escape(item.targetId)}">${this.escape(item.targetIdentifier)}</a></td>
                <td>${this.escape(item.name)}</td>
                <td>${this.escape(item.dateStart)}</td>
            </tr>`).join('');
            const active = rollup.activeSession
                ? `<div class="alert alert-success"><strong>Active timer:</strong> ${this.escape(rollup.activeSession.name)} · ${this.escape((rollup.activeSession.attendeeNames || []).join(', '))}</div>`
                : '';
            return `<section class="panel panel-default elevate-rm-target-controls elevate-rm-readonly-rollup">
                <header class="panel-heading"><strong>Resource Management</strong> <span class="label label-default">Read only</span></header>
                <div class="panel-body">${active}<div class="elevate-rm-kpis">
                    <div><span>Next 7 days</span><strong>${this.duration(rollup.scheduledSeconds)}</strong></div>
                    <div><span>Bookable</span><strong>${this.duration(rollup.bookableSeconds)}</strong></div>
                    <div><span>Utilization</span><strong>${this.escape(rollup.utilizationPercent)}%</strong></div>
                </div>
                <h5>Active and Upcoming Work</h5>
                <div class="table-responsive"><table class="table table-striped"><thead><tr><th>Target</th><th>Work Block</th><th>Scheduled</th></tr></thead>
                <tbody>${rows || '<tr><td colspan="3" class="text-muted">No upcoming assignments.</td></tr>'}</tbody></table></div></div>
            </section>`;
        }

        const rows = (rollup.recentTargets || []).map(item => `<tr>
            <td><a href="#${this.escape(item.entityType)}/view/${this.escape(item.id)}">${this.escape(item.identifier || item.name)}</a></td>
            <td>${this.escape(item.lifecycle)}</td><td>${this.escape(item.completionPercent)}%</td>
        </tr>`).join('');
        return `<section class="panel panel-default elevate-rm-target-controls elevate-rm-readonly-rollup">
            <header class="panel-heading"><strong>Logged Time</strong> <span class="label label-default">Read only</span></header>
            <div class="panel-body"><div class="elevate-rm-kpis">
                <div><span>Related targets</span><strong>${this.escape(rollup.packageCount)}</strong></div>
                <div><span>Time Entries</span><strong>${this.escape(rollup.entryCount)}</strong></div>
                <div><span>Elapsed</span><strong>${this.duration(rollup.elapsedSeconds)}</strong></div>
                <div><span>Labour</span><strong>${this.duration(rollup.labourSeconds)}</strong></div>
            </div>
            <h5>Recent Related Targets</h5>
            <div class="table-responsive"><table class="table table-striped"><thead><tr><th>Target</th><th>Stage</th><th>Progress</th></tr></thead>
            <tbody>${rows || '<tr><td colspan="3" class="text-muted">No related time has been logged.</td></tr>'}</tbody></table></div></div>
        </section>`;
    }

    openAction(action) {
        this.view.createView('elevateRmAction', 'elevate-resource-management:views/time-action-modal', {
            action,
            context: this.context,
            targetType: this.view.model.entityType,
            targetId: this.view.model.id,
        }).then(view => {
            this.listenToOnce(view, 'completed', () => {
                view.close();
                this.view.model.fetch().then(() => this.view.reRender());
            });
            view.render();
        });
    }

    startElapsedClock() {
        this.stopElapsedClock();
        if (!this.context.activeSession) return;
        this.timerInterval = window.setInterval(() => {
            this.view.$el.find('[data-name="elapsedTimer"]').text(
                this.elapsed(this.context.activeSession.startedAt)
            );
        }, 1000);
    }

    stopElapsedClock() {
        if (this.timerInterval) {
            window.clearInterval(this.timerInterval);
            this.timerInterval = null;
        }
    }

    elapsed(startedAt) {
        const start = Date.parse(String(startedAt || '').replace(' ', 'T') + 'Z');
        return this.duration(Math.max(0, Math.floor((Date.now() - start) / 1000)));
    }

    duration(seconds) {
        const value = Number(seconds || 0);
        return `${Math.floor(value / 3600)}h ${String(Math.floor(value % 3600 / 60)).padStart(2, '0')}m`;
    }

    escape(value) {
        return Handlebars.Utils.escapeExpression(String(value == null ? '' : value));
    }

    t(key) {
        return this.escape(this.view.translate(
            key,
            'labels',
            'ElevateResourceManagement'
        ));
    }

    tm(key) {
        return this.escape(this.view.translate(
            key,
            'messages',
            'ElevateResourceManagement'
        ));
    }
}

Object.assign(TargetControls.prototype, Backbone.Events);

export default TargetControls;
