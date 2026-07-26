class TargetControls {
        constructor(view) {
            this.view = view;
            this.context = null;
        }

        process() {
            this.listenTo(this.view, 'after:render', () => this.load());
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

            if (!this.context.eligible) {
                return;
            }

            const selector = this.view.$el.find('.record').first();
            if (!selector.length || selector.find('.elevate-rm-target-controls').length) {
                return;
            }

            selector.prepend(this.renderControls());
            selector.find('.elevate-rm-target-controls [data-action]').on('click', event => {
                this.openAction(event.currentTarget.dataset.action);
            });
        }

        renderControls() {
            const actions = this.context.actions || {};
            const buttons = [
                ['plan', 'Plan Work', 'primary'],
                ['reportIn', 'Report In', 'success'],
                ['milestone', 'Mark Milestone', 'primary'],
                ['finish', "We're Done", 'success'],
                ['manualEntry', 'Manual Time Entry', 'default'],
            ].filter(([key]) => actions[key])
                .map(([key, label, style]) =>
                    `<button class="btn btn-${style}" data-action="${key}">${Handlebars.Utils.escapeExpression(label)}</button>`
                ).join('');

            return `<section class="panel panel-default elevate-rm-target-controls" aria-label="Elevate time management">
                <header class="panel-heading"><strong>Elevate Resource Management</strong></header>
                <div class="panel-body elevate-rm-actions">${buttons}</div>
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
}

Object.assign(TargetControls.prototype, Backbone.Events);

export default TargetControls;
