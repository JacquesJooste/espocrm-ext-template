import ModalView from 'views/modal';

export default class extends ModalView {
    className = 'dialog dialog-record elevate-rm-action-modal';
    backdrop = true;
    fitHeight = true;

    templateContent = `
        <div class="record">
            {{#if showInstance}}
            <div class="form-group"><label>Instance</label><select class="form-control" data-name="instanceId">
                {{#each instances}}<option value="{{id}}">{{name}}</option>{{/each}}
            </select></div>
            {{/if}}
            {{#if showScheduledStart}}
            <div class="form-group"><label>Scheduled Start</label><input type="datetime-local" class="form-control" data-name="scheduledStart"></div>
            {{/if}}
            {{#if showBlock}}
            <div class="form-group"><label>Work Block</label><select class="form-control" data-name="blockId">
                {{#each blocks}}<option value="{{id}}">{{name}}</option>{{/each}}
            </select></div>
            {{/if}}
            {{#if showTimes}}
            <div class="row"><div class="col-sm-6 form-group"><label>Start</label><input type="datetime-local" class="form-control" data-name="start"></div>
            <div class="col-sm-6 form-group"><label>End</label><input type="datetime-local" class="form-control" data-name="end"></div></div>
            {{/if}}
            {{#if showAttendees}}
            <div class="form-group"><label>Attending Users</label><select multiple class="form-control" data-name="attendeeIds">
                {{#each users}}<option value="{{id}}">{{name}}</option>{{/each}}
            </select></div>
            {{/if}}
            {{#if showNote}}
            <div class="form-group"><label>Work Note</label><textarea class="form-control" data-name="workNote"></textarea></div>
            <div class="form-group"><label><input type="checkbox" data-name="userFlagged"> Flag Time Entry</label></div>
            <div class="form-group"><label>Reason for Drag</label><select class="form-control" data-name="dragReason">
                <option value="">None</option><option>InaccurateEstimate</option><option>Incident</option><option>Custom</option>
            </select></div>
            {{/if}}
            <div class="alert alert-warning hidden elevate-rm-early-warning">Scheduled Start is in the future. Early check-in will be recorded.</div>
        </div>`;

    setup() {
        super.setup();
        this.action = this.options.action;
        this.context = this.options.context;
        this.headerText = {
            plan: 'Plan Work',
            reportIn: 'Report In',
            milestone: 'Mark Milestone',
            finish: "We're Done",
            manualEntry: 'Manual Time Entry',
        }[this.action];
        this.buttonList = [
            {name: 'confirm', label: 'Confirm', style: 'primary', onClick: () => this.submit()},
            {name: 'cancel', label: 'Cancel', onClick: () => this.close()},
        ];
        this.wait(this.loadUsers());
    }

    async loadUsers() {
        const response = await Espo.Ajax.getRequest('User', {
            where: [{type: 'and', value: [
                {type: 'equals', attribute: 'isActive', value: true},
                {type: 'in', attribute: 'type', value: ['regular', 'admin']},
            ]}],
            maxSize: 200,
            orderBy: 'name',
        });
        this.users = response.list || [];
    }

    afterRender() {
        super.afterRender();
        if (
            this.action === 'reportIn' &&
            this.context.package &&
            this.context.package.scheduledStart &&
            new Date(this.context.package.scheduledStart.replace(' ', 'T') + 'Z') > new Date()
        ) {
            this.$el.find('.elevate-rm-early-warning').removeClass('hidden');
        }
    }

    data() {
        return {
            instances: this.context.instances || [],
            blocks: (this.context.blocks || []).filter(item => item.status !== 'Completed'),
            users: this.users || [],
            showInstance: this.action === 'plan',
            showScheduledStart: this.action === 'plan',
            showBlock: ['milestone', 'finish', 'manualEntry'].includes(this.action),
            showTimes: this.action === 'manualEntry',
            showAttendees: ['plan', 'reportIn', 'manualEntry'].includes(this.action),
            showNote: ['milestone', 'finish', 'manualEntry'].includes(this.action),
        };
    }

    values() {
        const value = name => this.$el.find(`[data-name="${name}"]`).val();
        const utc = raw => raw ? new Date(raw).toISOString().slice(0, 19).replace('T', ' ') : null;
        return {
            instanceId: value('instanceId'),
            scheduledStart: utc(value('scheduledStart')),
            blockId: value('blockId'),
            attendeeIds: value('attendeeIds') || [],
            start: utc(value('start')),
            end: utc(value('end')),
            workNote: value('workNote') || '',
            userFlagged: this.$el.find('[data-name="userFlagged"]').is(':checked'),
            dragReason: value('dragReason') || '',
            clientActionId: (crypto.randomUUID ? crypto.randomUUID() : `${Date.now()}-${Math.random()}`),
        };
    }

    async submit() {
        const data = this.values();
        const packageId = this.context.package && this.context.package.id;
        let request;

        this.disableButton('confirm');
        try {
            if (this.action === 'plan') {
                request = Espo.Ajax.postRequest('ElevateResourceManagement/packages', {
                    instanceId: data.instanceId,
                    targetType: this.options.targetType,
                    targetId: this.options.targetId,
                    scheduledStart: data.scheduledStart,
                    attendeeIds: data.attendeeIds,
                });
            } else if (this.action === 'reportIn') {
                request = Espo.Ajax.postRequest('ElevateResourceManagement/sessions/report-in', {
                    packageId, attendeeIds: data.attendeeIds, clientActionId: data.clientActionId,
                });
            } else if (this.action === 'milestone') {
                request = Espo.Ajax.postRequest(`ElevateResourceManagement/sessions/${this.context.activeSession.id}/milestone`, data);
            } else if (this.action === 'finish') {
                request = Espo.Ajax.postRequest(`ElevateResourceManagement/sessions/${this.context.activeSession.id}/finish`, data);
            } else {
                request = Espo.Ajax.postRequest('ElevateResourceManagement/time-entries/manual', {...data, packageId});
            }
            await request;
            Espo.Ui.success('Time management updated.');
            this.trigger('completed');
        } catch (e) {
            Espo.Ui.error((e && e.message) || 'The operation could not be completed.');
            this.enableButton('confirm');
        }
    }
}
