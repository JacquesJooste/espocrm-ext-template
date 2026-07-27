class InstanceGuidance {
    constructor(view) {
        this.view = view;
        this.wasNew = Boolean(view.model?.isNew());
    }

    process() {
        this.listenTo(this.view, 'after:render', () => this.renderGuidance());
        if (this.wasNew) {
            this.listenToOnce(this.view.model, 'after:save', () => {
                localStorage.setItem('elevateRmInstanceId', this.view.model.id);
                window.setTimeout(() => {
                    this.view.getRouter().navigate('#ElevateResourceManagement/library', {
                        trigger: true,
                    });
                }, 50);
            });
        }
    }

    renderGuidance() {
        const $record = this.view.$el.find('.record').first();
        if (!$record.length || $record.find('.elevate-rm-instance-guide').length) {
            return;
        }
        const targetType = this.view.model.get('targetEntityType');
        const actions = this.view.model.id && targetType
            ? `<div class="elevate-rm-actions">
                <a class="btn btn-primary" href="#ElevateResourceManagement/library" data-action="open-library">Build Default Work Blocks</a>
                <a class="btn btn-default" href="#${this.escape(targetType)}">Open ${this.escape(targetType)} Records</a>
              </div>`
            : '';
        const html = `<section class="alert alert-info elevate-rm-instance-guide">
            <div>
                <strong>Instance setup</strong>
                <ol><li>Map the target and workflow policies.</li><li>Select or build default Work Blocks.</li><li>Open target records and start work.</li></ol>
            </div>${actions}
        </section>`;
        $record.prepend(html);
        $record.find('[data-action="open-library"]').on('click', () => {
            if (this.view.model.id) {
                localStorage.setItem('elevateRmInstanceId', this.view.model.id);
            }
        });
    }

    escape(value) {
        return Handlebars.Utils.escapeExpression(String(value == null ? '' : value));
    }
}

Object.assign(InstanceGuidance.prototype, Backbone.Events);

export default InstanceGuidance;
