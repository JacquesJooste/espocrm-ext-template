class TargetList {
        constructor(view) {
            this.view = view;
            this.requestKey = null;
        }

        process() {
            this.listenTo(this.view, 'after:render', () => this.load());
        }

        async load() {
            const collection = this.view.collection;
            if (!collection || !collection.entityType || collection.entityType.startsWith('ElevateRm')) {
                return;
            }

            const ids = collection.models.map(model => model.id).filter(Boolean);
            const requestKey = ids.join(',');
            if (!ids.length || requestKey === this.requestKey) {
                return;
            }
            this.requestKey = requestKey;

            let response;
            try {
                response = await Espo.Ajax.postRequest('ElevateResourceManagement/context-bulk', {
                    entityType: collection.entityType,
                    ids,
                });
            } catch (e) {
                return;
            }

            Object.entries(response.items || {}).forEach(([id, context]) => {
                if (!context.eligible) {
                    return;
                }
                const row = this.view.$el.find(`tr[data-id="${CSS.escape(id)}"]`);
                const cell = row.find('.cell[data-name="name"]').first();
                if (!cell.length || cell.find('.elevate-rm-row-launcher').length) {
                    return;
                }
                const button = $('<button type="button" class="btn btn-link btn-xs elevate-rm-row-launcher" title="Time management"><span class="fas fa-stopwatch"></span></button>');
                button.on('click', event => {
                    event.preventDefault();
                    event.stopPropagation();
                    this.view.getRouter().navigate(`#${collection.entityType}/view/${id}`, {trigger: true});
                });
                cell.append(button);
            });
        }
}

Object.assign(TargetList.prototype, Backbone.Events);

export default TargetList;
