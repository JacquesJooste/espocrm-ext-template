class TargetListLauncher {
        constructor(view) {
            this.view = view;
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
                const context = await Espo.Ajax.getRequest(
                    `ElevateResourceManagement/context/${encodeURIComponent(model.entityType)}/${encodeURIComponent(model.id)}`
                );
                if (!context.eligible || this.view.$el.find('.elevate-rm-row-launcher').length) {
                    return;
                }
                const button = $('<button type="button" class="btn btn-link btn-xs elevate-rm-row-launcher" title="Time management"><span class="fas fa-stopwatch"></span></button>');
                button.on('click', event => {
                    event.preventDefault();
                    event.stopPropagation();
                    this.view.getRouter().navigate(`#${model.entityType}/view/${model.id}`, {trigger: true});
                });
                this.view.$el.find('.cell[data-name="name"]').first().append(button);
            } catch (e) {
                // Eligibility is intentionally silent for unrelated or inaccessible rows.
            }
        }
}

Object.assign(TargetListLauncher.prototype, Backbone.Events);

export default TargetListLauncher;
