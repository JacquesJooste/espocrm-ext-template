import ModalView from 'views/modal';
import {fetchAllRecords} from
    'elevate-resource-management:utils/fetch-all-records';

export default class extends ModalView {
    className = 'dialog dialog-record elevate-rm-action-modal';
    backdrop = true;
    fitHeight = true;

    templateContent = `
        <div class="record">
            {{#if showLogMode}}
            <div class="form-group"><label>{{translate 'timeEntryMethod' scope='ElevateResourceManagement' category='labels'}}</label><select class="form-control" data-name="logMode">
                <option value="timer">{{translate 'startLiveTimer' scope='ElevateResourceManagement' category='labels'}}</option>
                <option value="manual">{{translate 'addManualEntry' scope='ElevateResourceManagement' category='labels'}}</option>
            </select><p class="help-block">{{translate 'timerRecommended' scope='ElevateResourceManagement' category='messages'}}</p></div>
            {{/if}}
            {{#if showWorkSummary}}
            <div class="elevate-rm-work-summary">
                {{#each workBlocks}}
                <section class="panel panel-default">
                    <div class="panel-heading"><strong>{{name}}</strong><span>{{completionPercent}}%</span></div>
                    <div class="panel-body">
                        {{#each items}}<div class="elevate-rm-work-item-status"><span>{{nameSnapshot}}</span><span class="label label-default">{{status}}</span></div>{{/each}}
                    </div>
                </section>
                {{/each}}
            </div>
            {{/if}}
            {{#if showOperation}}
            <div class="form-group"><label>{{translate 'workBlockAction' scope='ElevateResourceManagement' category='labels'}}</label><select class="form-control" data-name="operation">
                <option value="add">{{translate 'addWorkBlocks' scope='ElevateResourceManagement' category='labels'}}</option>
                <option value="reschedule">{{translate 'rescheduleRemaining' scope='ElevateResourceManagement' category='labels'}}</option>
            </select></div>
            {{/if}}
            {{#if showInstance}}
            <div class="form-group"><label>{{translate 'instance' scope='ElevateResourceManagement' category='labels'}}</label><select class="form-control" data-name="instanceId">
                {{#each instances}}<option value="{{id}}">{{name}}</option>{{/each}}
            </select></div>
            {{/if}}
            {{#if showDefinitions}}
            <div class="form-group" data-section="definitions"><label>{{translate 'workBlocks' scope='ElevateResourceManagement' category='labels'}}</label><select multiple class="form-control elevate-rm-tall-select" data-name="templateIds">
                {{#each definitions}}<option value="{{id}}" data-instance-id="{{instanceId}}" {{#if isDefault}}selected{{/if}}>{{name}} · {{duration}}</option>{{/each}}
            </select><p class="help-block">{{translate 'chooseReusableWorkBlocks' scope='ElevateResourceManagement' category='messages'}}</p></div>
            {{/if}}
            {{#if showScheduledBlock}}
            <div class="form-group hidden" data-section="reschedule"><label>{{translate 'workBlockSchedule' scope='ElevateResourceManagement' category='labels'}}</label><select class="form-control" data-name="scheduledBlockId">
                {{#each schedules}}<option value="{{id}}">{{name}} · {{dateStart}}</option>{{/each}}
            </select></div>
            {{/if}}
            {{#if showScheduledStart}}
            <div class="form-group"><label>{{translate 'scheduledStart' scope='ElevateResourceManagement' category='labels'}}</label><input type="datetime-local" class="form-control" data-name="scheduledStart"></div>
            {{/if}}
            {{#if showWorkItem}}
            <div class="form-group"><label>{{translate 'workItem' scope='ElevateResourceManagement' category='labels'}}</label><select class="form-control" data-name="workItemRunId">
                {{#each workItems}}<option value="{{id}}">{{blockName}} · {{nameSnapshot}} ({{duration}})</option>{{/each}}
            </select></div>
            {{/if}}
            {{#if showTimes}}
            <div data-section="manual-entry" class="{{#if hideManualEntry}}hidden{{/if}}">
                <div class="row"><div class="col-sm-6 form-group"><label>{{translate 'start' scope='ElevateResourceManagement' category='labels'}}</label><input type="datetime-local" class="form-control" data-name="start"></div>
                <div class="col-sm-6 form-group"><label>{{translate 'end' scope='ElevateResourceManagement' category='labels'}}</label><input type="datetime-local" class="form-control" data-name="end"></div></div>
            </div>
            {{/if}}
            {{#if showAttendees}}
            <div class="form-group"><label>{{translate 'attendingUsers' scope='ElevateResourceManagement' category='labels'}}</label><select multiple class="form-control elevate-rm-tall-select" data-name="attendeeIds">
                {{#each users}}<option value="{{id}}" {{#if selected}}selected{{/if}}>{{name}}</option>{{/each}}
            </select></div>
            {{/if}}
            {{#if showNote}}
            <div data-section="entry-note" class="{{#if hideEntryNote}}hidden{{/if}}">
                <div class="form-group"><label>{{translate 'workNote' scope='ElevateResourceManagement' category='labels'}}</label><textarea class="form-control" data-name="workNote"></textarea></div>
                <div class="row"><div class="col-sm-6 form-group"><label><input type="checkbox" data-name="userFlagged"> {{translate 'highVisibility' scope='ElevateResourceManagement' category='labels'}}</label></div>
                <div class="col-sm-6 form-group"><label><input type="checkbox" data-name="complete" {{#if stopAction}}checked{{/if}}> {{translate 'completeWorkItem' scope='ElevateResourceManagement' category='labels'}}</label></div></div>
                <div class="form-group"><label>{{translate 'reasonForDrag' scope='ElevateResourceManagement' category='labels'}}</label><select class="form-control" data-name="dragReason">
                    <option value="">{{translate 'none' scope='ElevateResourceManagement' category='labels'}}</option><option value="InaccurateEstimate">Inaccurate Estimate</option><option value="Incident">Incident</option><option value="Custom">Custom</option>
                </select></div>
            </div>
            {{/if}}
            <div class="alert alert-warning hidden elevate-rm-early-warning">{{translate 'scheduledStartFuture' scope='ElevateResourceManagement' category='messages'}}</div>
        </div>`;

    setup() {
        super.setup();
        this.action = this.options.action;
        this.context = this.options.context;
        this.headerText = this.translate({
            workBlocks: 'workBlocks',
            logTime: 'logTime',
            stopTimer: 'stopTimer',
            manualEntry: 'manualTimeEntry',
        }[this.action], 'labels', 'ElevateResourceManagement');
        this.buttonList = [
            {
                name: 'confirm',
                label: this.translate(
                    this.action === 'stopTimer'
                        ? 'stopTimer'
                        : this.action === 'logTime' ? 'startTimer' : 'confirm',
                    'labels',
                    'ElevateResourceManagement'
                ),
                style: 'primary',
                onClick: () => this.submit(),
            },
            {
                name: 'cancel',
                label: this.translate('cancel', 'labels', 'ElevateResourceManagement'),
                onClick: () => this.close(),
            },
        ];
        this.wait(this.loadUsers());
    }

    async loadUsers() {
        const users = await fetchAllRecords('User', {
            where: [{type: 'and', value: [
                {type: 'equals', attribute: 'isActive', value: true},
                {type: 'in', attribute: 'type', value: ['regular', 'admin']},
            ]}],
            orderBy: 'name',
        });
        const currentUserId = this.getUser()?.id;
        this.users = users.map(user => ({
            ...user,
            selected: user.id === currentUserId,
        }));
    }

    afterRender() {
        super.afterRender();
        this.$el.find('[data-name="operation"]').on('change', event => {
            const reschedule = event.currentTarget.value === 'reschedule';
            this.$el.find('[data-section="definitions"]').toggleClass('hidden', reschedule);
            this.$el.find('[data-section="reschedule"]').toggleClass('hidden', !reschedule);
        });
        this.$el.find('[data-name="instanceId"]').on('change', () => this.filterDefinitions());
        this.$el.find('[data-name="logMode"]').on('change', event => {
            const manual = event.currentTarget.value === 'manual';
            this.$el.find('[data-section="manual-entry"], [data-section="entry-note"]').toggleClass('hidden', !manual);
            this.$el.find('[data-name="complete"]').closest('.form-group').toggleClass('hidden', manual);
            const confirm = this.buttonList.find(item => item.name === 'confirm');
            if (confirm) confirm.label = this.translate(
                manual ? 'addEntry' : 'startTimer',
                'labels',
                'ElevateResourceManagement'
            );
            this.reRenderFooter();
        });
        this.setDefaultDates();
        this.filterDefinitions();
    }

    data() {
        const workItems = [];
        const schedules = [];
        (this.context.workBlocks || []).forEach(block => {
            (block.items || []).filter(item => !['Completed', 'Cancelled'].includes(item.status)).forEach(item => {
                workItems.push({
                    ...item,
                    blockName: block.name,
                    duration: this.duration(item.estimatedSeconds),
                });
            });
            (block.schedules || []).filter(schedule => schedule.status !== 'Cancelled').forEach(schedule => {
                schedules.push(schedule);
            });
        });
        return {
            instances: this.context.instances || [],
            definitions: (this.context.availableWorkBlocks || []).map(item => ({
                ...item,
                duration: this.duration(item.estimatedSeconds),
            })),
            workBlocks: this.context.workBlocks || [],
            workItems,
            schedules,
            users: this.users || [],
            showLogMode: this.action === 'logTime',
            showWorkSummary: this.action === 'workBlocks' && Boolean(this.context.package),
            showOperation: this.action === 'workBlocks' && Boolean(this.context.package),
            showInstance: this.action === 'workBlocks' && !this.context.package,
            showDefinitions: this.action === 'workBlocks',
            showScheduledBlock: this.action === 'workBlocks' && Boolean(this.context.package),
            showScheduledStart: this.action === 'workBlocks',
            showWorkItem: ['logTime', 'manualEntry'].includes(this.action),
            showTimes: ['logTime', 'manualEntry'].includes(this.action),
            showAttendees: ['workBlocks', 'logTime', 'manualEntry'].includes(this.action),
            showNote: ['stopTimer', 'logTime', 'manualEntry'].includes(this.action),
            hideManualEntry: this.action === 'logTime',
            hideEntryNote: this.action === 'logTime',
            stopAction: this.action === 'stopTimer',
        };
    }

    filterDefinitions() {
        const instanceId = this.$el.find('[data-name="instanceId"]').val() ||
            this.context.package?.instanceId;
        if (!instanceId) return;
        this.$el.find('[data-name="templateIds"] option').each((_, option) => {
            const visible = option.dataset.instanceId === instanceId;
            option.hidden = !visible;
            if (!visible) option.selected = false;
        });
    }

    values() {
        const value = name => this.$el.find(`[data-name="${name}"]`).val();
        const utc = raw => raw ? new Date(raw).toISOString().slice(0, 19).replace('T', ' ') : null;
        return {
            instanceId: value('instanceId') || this.context.package?.instanceId,
            operation: value('operation') || 'add',
            logMode: value('logMode') || 'timer',
            templateIds: value('templateIds') || [],
            scheduledBlockId: value('scheduledBlockId'),
            scheduledStart: utc(value('scheduledStart')),
            workItemRunId: value('workItemRunId'),
            attendeeIds: value('attendeeIds') || [],
            start: utc(value('start')),
            end: utc(value('end')),
            workNote: value('workNote') || '',
            userFlagged: this.$el.find('[data-name="userFlagged"]').is(':checked'),
            complete: this.$el.find('[data-name="complete"]').is(':checked'),
            dragReason: value('dragReason') || '',
            clientActionId: crypto.randomUUID ? crypto.randomUUID() : `${Date.now()}-${Math.random()}`,
        };
    }

    async submit() {
        const data = this.values();
        const packageId = this.context.package?.id;
        this.disableButton('confirm');
        try {
            if (this.action === 'workBlocks' && !packageId) {
                await Espo.Ajax.postRequest('ElevateResourceManagement/packages', {
                    instanceId: data.instanceId,
                    targetType: this.options.targetType,
                    targetId: this.options.targetId,
                    scheduledStart: data.scheduledStart,
                    templateIds: data.templateIds,
                    attendeeIds: data.attendeeIds,
                });
            } else if (this.action === 'workBlocks' && data.operation === 'add') {
                await Espo.Ajax.postRequest(
                    `ElevateResourceManagement/packages/${packageId}/work-blocks`,
                    data
                );
            } else if (this.action === 'workBlocks') {
                await Espo.Ajax.postRequest(
                    `ElevateResourceManagement/scheduled-blocks/${data.scheduledBlockId}/reschedule-remaining`,
                    {dateStart: data.scheduledStart, attendeeIds: data.attendeeIds}
                );
            } else if (this.action === 'logTime' && data.logMode !== 'manual') {
                await Espo.Ajax.postRequest('ElevateResourceManagement/timers/start', {
                    packageId,
                    workItemRunId: data.workItemRunId,
                    attendeeIds: data.attendeeIds,
                    clientActionId: data.clientActionId,
                });
            } else if (this.action === 'stopTimer') {
                await Espo.Ajax.postRequest(
                    `ElevateResourceManagement/timers/${this.context.activeSession.id}/stop`,
                    data
                );
            } else {
                const item = (this.context.workBlocks || [])
                    .flatMap(block => block.items || [])
                    .find(candidate => candidate.id === data.workItemRunId);
                await Espo.Ajax.postRequest('ElevateResourceManagement/time-entries/manual', {
                    ...data,
                    packageId,
                    blockId: item?.scheduledBlockId,
                });
            }
            Espo.Ui.success(this.translate(
                'timeManagementUpdated',
                'messages',
                'ElevateResourceManagement'
            ));
            this.trigger('completed');
        } catch (error) {
            Espo.Ui.error(error?.message || this.translate(
                'operationFailed',
                'messages',
                'ElevateResourceManagement'
            ));
            this.enableButton('confirm');
        }
    }

    duration(seconds) {
        const value = Number(seconds || 0);
        return `${Math.floor(value / 3600)}h ${String(Math.floor(value % 3600 / 60)).padStart(2, '0')}m`;
    }

    setDefaultDates() {
        const localValue = date => {
            const offset = date.getTimezoneOffset() * 60000;
            return new Date(date.getTime() - offset).toISOString().slice(0, 16);
        };
        const now = new Date();
        const nextQuarter = new Date(Math.ceil(now.getTime() / 900000) * 900000);
        const oneHourAgo = new Date(now.getTime() - 3600000);
        this.$el.find('[data-name="scheduledStart"]').val(localValue(nextQuarter));
        this.$el.find('[data-name="start"]').val(localValue(oneHourAgo));
        this.$el.find('[data-name="end"]').val(localValue(now));
    }
}
