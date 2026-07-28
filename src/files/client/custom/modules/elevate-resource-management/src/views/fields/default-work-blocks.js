import BaseFieldView from 'views/fields/base';
import {fetchAllRecords} from
    'elevate-resource-management:utils/fetch-all-records';

export default class extends BaseFieldView {
    type = 'jsonArray';

    detailTemplateContent = `
        <div data-name="defaultWorkBlocks"></div>
        <p class="help-block">Lower positions are planned first.</p>
    `;

    editTemplateContent = `
        <div data-name="defaultWorkBlocks"></div>
        <p class="help-block">Select defaults and use the arrows to set their planning order. Lower positions are planned first.</p>
    `;

    setup() {
        super.setup();
        this.items = [];
        this.selectedIds = [];
        this.wait(this.loadItems());
    }

    async loadItems() {
        if (!this.model.id) {
            return;
        }
        this.items = await fetchAllRecords('ElevateRmWorkBlockTemplate', {
            where: [{
                type: 'equals',
                attribute: 'instanceId',
                value: this.model.id,
            }],
            orderBy: 'defaultOrder',
        });
        const authoritative = this.items
            .filter(item => item.isDefault)
            .sort((a, b) => Number(a.defaultOrder || 0) - Number(b.defaultOrder || 0))
            .map(item => item.id);
        const legacy = Array.isArray(this.model.get(this.name))
            ? this.model.get(this.name)
            : [];
        this.selectedIds = authoritative.length ? authoritative : legacy.filter(id =>
            this.items.some(item => item.id === id)
        );
    }

    afterRender() {
        super.afterRender();
        this.renderItems();
    }

    renderItems() {
        const $container = this.$el.find('[data-name="defaultWorkBlocks"]');
        if (!this.model.id) {
            $container.html('<p class="text-muted">Save the Instance, then build its default Work Blocks in the Library.</p>');
            return;
        }
        if (!this.items.length) {
            $container.html('<p class="text-muted">No Work Blocks exist for this Instance yet.</p>');
            return;
        }

        const ordered = [
            ...this.selectedIds
                .map(id => this.items.find(item => item.id === id))
                .filter(Boolean),
            ...this.items.filter(item => !this.selectedIds.includes(item.id)),
        ];
        $container.html(ordered.map(item => {
            const selected = this.selectedIds.includes(item.id);
            const position = this.selectedIds.indexOf(item.id);
            const controls = this.mode === 'edit'
                ? `<span class="elevate-rm-default-controls">
                    <button type="button" class="btn btn-link btn-xs" data-action="up" data-id="${this.escape(item.id)}" ${!selected || position === 0 ? 'disabled' : ''} aria-label="Move up"><span class="fas fa-arrow-up"></span></button>
                    <button type="button" class="btn btn-link btn-xs" data-action="down" data-id="${this.escape(item.id)}" ${!selected || position === this.selectedIds.length - 1 ? 'disabled' : ''} aria-label="Move down"><span class="fas fa-arrow-down"></span></button>
                  </span>`
                : '';
            return `<div class="elevate-rm-default-work-block ${selected ? 'is-selected' : ''}">
                ${this.mode === 'edit' ? `<label><input type="checkbox" data-id="${this.escape(item.id)}" ${selected ? 'checked' : ''}>` : '<span>'}
                ${selected ? `<strong>${position + 1}.</strong>` : ''} ${this.escape(item.name)}
                ${this.mode === 'edit' ? '</label>' : '</span>'}
                ${controls}
            </div>`;
        }).join(''));

        if (this.mode !== 'edit') {
            $container.find('.elevate-rm-default-work-block:not(.is-selected)').hide();
            if (!this.selectedIds.length) {
                $container.html('<p class="text-muted">No default Work Blocks selected.</p>');
            }
            return;
        }

        $container.find('input[type="checkbox"]').on('change', event => {
            const id = event.currentTarget.dataset.id;
            if (event.currentTarget.checked) {
                if (!this.selectedIds.includes(id)) this.selectedIds.push(id);
            } else {
                this.selectedIds = this.selectedIds.filter(value => value !== id);
            }
            this.renderItems();
        });
        $container.find('[data-action]').on('click', event => {
            const id = event.currentTarget.dataset.id;
            const index = this.selectedIds.indexOf(id);
            const next = event.currentTarget.dataset.action === 'up' ? index - 1 : index + 1;
            if (index < 0 || next < 0 || next >= this.selectedIds.length) return;
            [this.selectedIds[index], this.selectedIds[next]] =
                [this.selectedIds[next], this.selectedIds[index]];
            this.renderItems();
        });
    }

    fetch() {
        return {[this.name]: [...this.selectedIds]};
    }

    escape(value) {
        return Handlebars.Utils.escapeExpression(String(value == null ? '' : value));
    }
}
