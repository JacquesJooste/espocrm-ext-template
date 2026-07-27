import ModalView from 'views/modal';

export default class extends ModalView {
    className = 'dialog dialog-record elevate-rm-work-block-editor';
    backdrop = true;
    fitHeight = true;

    templateContent = `
        <div class="record elevate-rm-composite-editor">
            <div class="row">
                <div class="col-sm-6 form-group">
                    <label>{{translate 'workBlock' scope='ElevateResourceManagement' category='labels'}}</label>
                    <input class="form-control" data-name="name" value="{{workBlock.name}}">
                </div>
                <div class="col-sm-6 form-group">
                    <label>{{translate 'instance' scope='ElevateResourceManagement' category='labels'}}</label>
                    <select class="form-control" data-name="instanceId">
                        {{#each instances}}<option value="{{id}}" {{#if selected}}selected{{/if}}>{{name}}</option>{{/each}}
                    </select>
                </div>
            </div>
            <div class="row">
                <div class="col-sm-3 form-group">
                    <label>{{translate 'milestoneType' scope='ElevateResourceManagement' category='labels'}}</label>
                    <select class="form-control" data-name="milestoneKind">
                        {{#each milestones}}<option value="{{value}}" {{#if selected}}selected{{/if}}>{{value}}</option>{{/each}}
                    </select>
                </div>
                <div class="col-sm-3 form-group">
                    <label>{{translate 'defaultPlanningOrder' scope='ElevateResourceManagement' category='labels'}}</label>
                    <input type="number" class="form-control" data-name="defaultOrder" value="{{workBlock.defaultOrder}}">
                </div>
                <div class="col-sm-3 form-group elevate-rm-checkbox">
                    <label><input type="checkbox" data-name="isDefault" {{#if workBlock.isDefault}}checked{{/if}}> {{translate 'defaultWorkBlock' scope='ElevateResourceManagement' category='labels'}}</label>
                </div>
                <div class="col-sm-3 form-group elevate-rm-checkbox">
                    <label><input type="checkbox" data-name="active" {{#if workBlock.active}}checked{{/if}}> {{translate 'active' scope='ElevateResourceManagement' category='labels'}}</label>
                </div>
            </div>
            <hr>
            <div class="elevate-rm-editor-heading">
                <div>
                    <h4>{{translate 'relatedWorkItems' scope='ElevateResourceManagement' category='labels'}}</h4>
                    <p class="text-muted">{{translate 'workItemDescriptionsCanonical' scope='ElevateResourceManagement' category='messages'}}</p>
                </div>
                <div>
                    <button type="button" class="btn btn-default btn-sm" data-action="add-existing">{{translate 'addExisting' scope='ElevateResourceManagement' category='labels'}}</button>
                    <button type="button" class="btn btn-default btn-sm" data-action="add-new">{{translate 'createWorkItem' scope='ElevateResourceManagement' category='labels'}}</button>
                </div>
            </div>
            <div data-name="rows"></div>
            <div class="elevate-rm-editor-total"><strong>{{translate 'totalEstimatedTime' scope='ElevateResourceManagement' category='labels'}}:</strong> <span data-name="total">0h 00m</span></div>
        </div>`;

    setup() {
        super.setup();
        this.workBlockId = this.options.workBlockId || null;
        this.rows = [];
        this.headerText = this.translate(
            this.workBlockId ? 'editWorkBlock' : 'createWorkBlock',
            'labels',
            'ElevateResourceManagement'
        );
        this.buttonList = [
            {
                name: 'save',
                label: this.translate('save', 'labels', 'ElevateResourceManagement'),
                style: 'primary',
                onClick: () => this.submit(),
            },
            {
                name: 'cancel',
                label: this.translate('cancel', 'labels', 'ElevateResourceManagement'),
                onClick: () => this.close(),
            },
        ];
        this.wait(this.load());
    }

    async load() {
        const [instances, workItems] = await Promise.all([
            Espo.Ajax.getRequest('ElevateRmInstance', {
                where: [{type: 'notEquals', attribute: 'status', value: 'Archived'}],
                maxSize: 200,
                orderBy: 'name',
            }),
            Espo.Ajax.getRequest('ElevateRmWorkItem', {
                where: [{type: 'equals', attribute: 'active', value: true}],
                maxSize: 500,
                orderBy: 'name',
            }),
        ]);
        this.instances = instances.list || [];
        this.workItems = workItems.list || [];

        if (this.workBlockId) {
            this.workBlock = await Espo.Ajax.getRequest(
                `ElevateResourceManagement/work-blocks/${this.workBlockId}/composition`
            );
            this.rows = (this.workBlock.items || []).map(item => ({
                id: item.id,
                workItemId: item.workItemId,
                estimateOverrideSeconds: item.estimateOverrideSeconds,
                isNew: false,
            }));
        } else {
            this.workBlock = {
                name: '',
                instanceId: this.options.instanceId || this.instances[0]?.id || '',
                milestoneKind: 'Normal',
                defaultOrder: 0,
                isDefault: false,
                active: true,
            };
        }
    }

    data() {
        return {
            workBlock: this.workBlock,
            instances: this.instances.map(item => ({
                ...item,
                selected: item.id === this.workBlock.instanceId,
            })),
            milestones: ['Normal', 'Deadline', 'Achievement', 'Delivery'].map(value => ({
                value,
                selected: value === this.workBlock.milestoneKind,
            })),
        };
    }

    afterRender() {
        super.afterRender();
        this.$el.find('[data-action="add-existing"]').on('click', () => {
            this.rows.push({
                workItemId: this.workItems[0]?.id || '',
                estimateOverrideSeconds: null,
                isNew: false,
            });
            this.renderRows();
        });
        this.$el.find('[data-action="add-new"]').on('click', () => {
            this.rows.push({
                isNew: true,
                name: '',
                description: '',
                defaultEstimateSeconds: 3600,
                estimateOverrideSeconds: null,
            });
            this.renderRows();
        });
        this.renderRows();
    }

    renderRows() {
        const html = this.rows.map((row, index) => this.rowHtml(row, index)).join('');
        this.$el.find('[data-name="rows"]').html(html || '<p class="text-muted">Add at least one Work Item.</p>');
        this.bindRows();
        this.updateTotal();
    }

    rowHtml(row, index) {
        const item = this.workItems.find(candidate => candidate.id === row.workItemId);
        const selectedEstimate = row.isNew
            ? Number(row.defaultEstimateSeconds || 0)
            : Number(row.estimateOverrideSeconds ?? item?.defaultEstimateSeconds ?? 0);
        const overrideChecked = !row.isNew && row.estimateOverrideSeconds != null;
        const controls = row.isNew
            ? `<div class="row">
                <div class="col-sm-4 form-group"><label>${this.t('workItem')}</label><input class="form-control" data-field="name" value="${this.escape(row.name)}"></div>
                <div class="col-sm-5 form-group"><label>${this.t('description')}</label><textarea class="form-control" data-field="description" rows="2">${this.escape(row.description)}</textarea></div>
                <div class="col-sm-3 form-group"><label>${this.t('estimatedTime')}</label>${this.durationHtml(selectedEstimate, 'default')}</div>
              </div>`
            : `<div class="row">
                <div class="col-sm-4 form-group"><label>${this.t('workItem')}</label>
                    <input type="search" class="form-control input-sm elevate-rm-work-item-search" data-field="itemSearch" placeholder="${this.t('searchWorkItems')}">
                    <select class="form-control" data-field="workItemId">
                    ${this.workItems.map(candidate => `<option value="${this.escape(candidate.id)}" ${candidate.id === row.workItemId ? 'selected' : ''}>${this.escape(candidate.name)}</option>`).join('')}
                </select></div>
                <div class="col-sm-5 form-group"><label>${this.t('description')}</label><div class="form-control-static" data-field="description">${this.escape(item?.description || '—')}</div></div>
                <div class="col-sm-3 form-group"><label><input type="checkbox" data-field="override" ${overrideChecked ? 'checked' : ''}> ${this.t('overrideEstimate')}</label>
                    <div data-field="override-duration" class="${overrideChecked ? '' : 'hidden'}">${this.durationHtml(selectedEstimate, 'override')}</div>
                    <div data-field="default-duration" class="${overrideChecked ? 'hidden' : 'form-control-static'}">${this.duration(item?.defaultEstimateSeconds || 0)}</div>
                </div>
              </div>`;

        return `<section class="panel panel-default elevate-rm-item-row" data-index="${index}">
            <div class="panel-heading elevate-rm-item-row-heading">
                <strong>${this.t('workItem')} ${index + 1}</strong>
                <span>
                    <button type="button" class="btn btn-link btn-sm" data-row-action="up" ${index === 0 ? 'disabled' : ''} aria-label="Move up"><span class="fas fa-arrow-up"></span></button>
                    <button type="button" class="btn btn-link btn-sm" data-row-action="down" ${index === this.rows.length - 1 ? 'disabled' : ''} aria-label="Move down"><span class="fas fa-arrow-down"></span></button>
                    <button type="button" class="btn btn-link btn-sm text-danger" data-row-action="remove" aria-label="Remove"><span class="fas fa-times"></span></button>
                </span>
            </div>
            <div class="panel-body">${controls}</div>
        </section>`;
    }

    durationHtml(seconds, prefix) {
        const hours = Math.floor(Number(seconds || 0) / 3600);
        const minutes = Math.floor(Number(seconds || 0) % 3600 / 60);
        return `<div class="elevate-rm-duration">
            <select class="form-control" data-field="${prefix}Hours">${Array.from({length: 25}, (_, value) =>
                `<option value="${value}" ${value === hours ? 'selected' : ''}>${String(value).padStart(2, '0')}</option>`
            ).join('')}</select><span>h</span>
            <select class="form-control" data-field="${prefix}Minutes">${[0, 15, 30, 45].map(value =>
                `<option value="${value}" ${value === minutes ? 'selected' : ''}>${String(value).padStart(2, '0')}</option>`
            ).join('')}</select><span>m</span>
        </div>`;
    }

    bindRows() {
        this.$el.find('.elevate-rm-item-row').each((_, element) => {
            const $row = $(element);
            const index = Number($row.data('index'));
            $row.find('[data-row-action]').on('click', event => {
                const action = event.currentTarget.dataset.rowAction;
                this.captureRows();
                if (action === 'remove') {
                    this.rows.splice(index, 1);
                } else if (action === 'up' && index > 0) {
                    [this.rows[index - 1], this.rows[index]] = [this.rows[index], this.rows[index - 1]];
                } else if (action === 'down' && index < this.rows.length - 1) {
                    [this.rows[index + 1], this.rows[index]] = [this.rows[index], this.rows[index + 1]];
                }
                this.renderRows();
            });
            $row.find('input, select, textarea').on('change input', event => {
                if (event.currentTarget.dataset.field === 'itemSearch') {
                    const query = String(event.currentTarget.value || '').trim().toLowerCase();
                    $row.find('[data-field="workItemId"] option').each((_, option) => {
                        option.hidden = query !== '' &&
                            !option.textContent.toLowerCase().includes(query);
                    });
                    return;
                }
                if (event.currentTarget.dataset.field === 'override') {
                    $row.find('[data-field="override-duration"]').toggleClass('hidden', !event.currentTarget.checked);
                    $row.find('[data-field="default-duration"]').toggleClass('hidden', event.currentTarget.checked);
                }
                if (event.currentTarget.dataset.field === 'workItemId') {
                    this.captureRows();
                    this.renderRows();
                    return;
                }
                this.updateTotal();
            });
        });
    }

    captureRows() {
        this.$el.find('.elevate-rm-item-row').each((_, element) => {
            const $row = $(element);
            const index = Number($row.data('index'));
            const row = this.rows[index];
            if (row.isNew) {
                row.name = String($row.find('[data-field="name"]').val() || '');
                row.description = String($row.find('[data-field="description"]').val() || '');
                row.defaultEstimateSeconds = this.readDuration($row, 'default');
            } else {
                row.workItemId = String($row.find('[data-field="workItemId"]').val() || '');
                row.estimateOverrideSeconds = $row.find('[data-field="override"]').is(':checked')
                    ? this.readDuration($row, 'override')
                    : null;
            }
        });
    }

    readDuration($row, prefix) {
        const hours = Number($row.find(`[data-field="${prefix}Hours"]`).val() || 0);
        const minutes = Number($row.find(`[data-field="${prefix}Minutes"]`).val() || 0);
        return hours * 3600 + minutes * 60;
    }

    updateTotal() {
        this.captureRows();
        const total = this.rows.reduce((sum, row) => {
            if (row.isNew) {
                return sum + Number(row.defaultEstimateSeconds || 0);
            }
            const item = this.workItems.find(candidate => candidate.id === row.workItemId);
            return sum + Number(row.estimateOverrideSeconds ?? item?.defaultEstimateSeconds ?? 0);
        }, 0);
        this.$el.find('[data-name="total"]').text(this.duration(total));
    }

    async submit() {
        this.captureRows();
        const name = String(this.$el.find('[data-name="name"]').val() || '').trim();
        if (!name || !this.rows.length) {
            Espo.Ui.error('Enter a Work Block name and at least one Work Item.');
            return;
        }
        const invalid = this.rows.some(row => {
            const seconds = row.isNew
                ? row.defaultEstimateSeconds
                : row.estimateOverrideSeconds;
            return row.isNew && (!row.name.trim() || seconds < 900 || seconds > 86400 || seconds % 900 !== 0) ||
                (!row.isNew && seconds != null && (seconds < 900 || seconds > 86400 || seconds % 900 !== 0));
        });
        if (invalid) {
            Espo.Ui.error(this.translate(
                'durationValidation',
                'messages',
                'ElevateResourceManagement'
            ));
            return;
        }

        const payload = {
            name,
            instanceId: this.$el.find('[data-name="instanceId"]').val(),
            milestoneKind: this.$el.find('[data-name="milestoneKind"]').val(),
            defaultOrder: Number(this.$el.find('[data-name="defaultOrder"]').val() || 0),
            isDefault: this.$el.find('[data-name="isDefault"]').is(':checked'),
            active: this.$el.find('[data-name="active"]').is(':checked'),
            items: this.rows.map((row, sequence) => row.isNew ? {
                sequence,
                create: {
                    name: row.name.trim(),
                    description: row.description,
                    defaultEstimateSeconds: row.defaultEstimateSeconds,
                },
            } : {
                id: row.id,
                workItemId: row.workItemId,
                estimateOverrideSeconds: row.estimateOverrideSeconds,
                sequence,
            }),
        };

        this.disableButton('save');
        try {
            if (this.workBlockId) {
                await Espo.Ajax.putRequest(
                    `ElevateResourceManagement/work-blocks/${this.workBlockId}`,
                    payload
                );
            } else {
                await Espo.Ajax.postRequest('ElevateResourceManagement/work-blocks', payload);
            }
            Espo.Ui.success(this.translate(
                'workBlockSaved',
                'messages',
                'ElevateResourceManagement'
            ));
            this.trigger('completed');
            this.close();
        } catch (error) {
            Espo.Ui.error(error?.message || this.translate(
                'workBlockSaveFailed',
                'messages',
                'ElevateResourceManagement'
            ));
            this.enableButton('save');
        }
    }

    duration(seconds) {
        const value = Number(seconds || 0);
        return `${Math.floor(value / 3600)}h ${String(Math.floor(value % 3600 / 60)).padStart(2, '0')}m`;
    }

    escape(value) {
        return Handlebars.Utils.escapeExpression(String(value == null ? '' : value));
    }

    t(key) {
        return this.escape(this.translate(key, 'labels', 'ElevateResourceManagement'));
    }
}
