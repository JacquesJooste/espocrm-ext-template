import EntityTypeFieldView from 'views/fields/entity-type';

class TargetEntityTypeFieldView extends EntityTypeFieldView {
    checkAvailability(entityType) {
        if (
            entityType === 'ElevateResourceManagement' ||
            entityType.startsWith('ElevateRm')
        ) {
            return false;
        }

        return super.checkAvailability(entityType);
    }

    setup() {
        super.setup();

        this.listenTo(this.model, `change:${this.name}`, () => {
            const entityType = this.model.get(this.name);

            if (!entityType || this.model.get('name')) {
                return;
            }

            this.model.set(
                'name',
                this.translate(entityType, 'scopeNames'),
            );
        });
    }
}

export default TargetEntityTypeFieldView;
