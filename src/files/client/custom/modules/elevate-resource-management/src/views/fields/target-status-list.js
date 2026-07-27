import ArrayFieldView from 'views/fields/array';

class TargetStatusListFieldView extends ArrayFieldView {
    setup() {
        this.listenTo(
            this.model,
            'change:targetEntityType change:statusField',
            () => this.refreshOptions(),
        );

        super.setup();
    }

    setupOptions() {
        const {options, translations} = this.buildOptions();

        this.params.options = options;
        this.params.translatedOptions = translations;
        this.params.allowCustomOptions = false;
    }

    refreshOptions() {
        const {options, translations} = this.buildOptions();

        this.translatedOptions = translations;
        this.setOptionList(options, false);
    }

    buildOptions() {
        const entityType = this.model.get('targetEntityType');
        const statusField = this.model.get('statusField');
        const fieldDefs = entityType && statusField ?
            this.getMetadata().get(`entityDefs.${entityType}.fields.${statusField}`) ?? {} :
            {};
        const options = Array.isArray(fieldDefs.options) ?
            [...fieldDefs.options] :
            [];
        const translations = {};

        options.forEach(value => {
            translations[value] = this.getLanguage()
                .translateOption(value, statusField, entityType);
        });

        return {options, translations};
    }
}

export default TargetStatusListFieldView;
