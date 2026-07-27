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
            const action = context.activeSession
                ? 'stopTimer'
                : (context.package && (context.workBlocks || []).length ? 'logTime' : 'workBlocks');
            const label = this.view.translate(
                action === 'stopTimer'
                    ? 'stopTimer'
                    : action === 'logTime' ? 'logTime' : 'addWorkBlocks',
                'labels',
                'ElevateResourceManagement'
            );
            const button = $(`<button type="button" class="btn btn-link btn-xs elevate-rm-row-launcher" title="${label}" aria-label="${label}">
                <span class="fas fa-${action === 'stopTimer' ? 'stop-circle text-danger' : 'stopwatch'}"></span>
            </button>`);
            button.on('click', event => {
                event.preventDefault();
                event.stopPropagation();
                this.openAction(id, action, context);
            });
            cell.append(button);
        });
    }

    openAction(id, action, context) {
        const collection = this.view.collection;
        this.view.createView('elevateRmListAction', 'elevate-resource-management:views/time-action-modal', {
            action,
            context,
            targetType: collection.entityType,
            targetId: id,
        }).then(view => {
            this.listenToOnce(view, 'completed', () => {
                view.close();
                this.requestKey = null;
                collection.fetch().then(() => this.view.reRender());
            });
            view.render();
        });
    }
}

Object.assign(TargetList.prototype, Backbone.Events);

export default TargetList;
