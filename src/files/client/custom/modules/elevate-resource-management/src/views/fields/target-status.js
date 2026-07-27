import EnumFieldView from 'views/fields/enum';

class TargetStatusFieldView extends EnumFieldView {
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
    }

    async refreshOptions() {
        const {options, translations} = this.buildOptions();

        this.setTranslatedOptions(translations);
        await this.setOptionList(options);
    }

    buildOptions() {
        const entityType = this.model.get('targetEntityType');
        const statusField = this.model.get('statusField');
        const fieldDefs = entityType && statusField ?
            this.getMetadata().get(`entityDefs.${entityType}.fields.${statusField}`) ?? {} :
            {};
        const options = [''].concat(
            Array.isArray(fieldDefs.options) ? fieldDefs.options : [],
        );
        const current = this.model.get(this.name);

        if (current && !options.includes(current)) {
            options.push(current);
        }

        const required = Boolean(
            this.model.getFieldParam(this.name, 'required')
        );
        const translations = {
            '': required ?
                'Select a status' :
                'Do not sync the target status',
        };

        options.slice(1).forEach(value => {
            translations[value] = this.getLanguage()
                .translateOption(value, statusField, entityType);
        });

        return {options, translations};
    }
}

export default TargetStatusFieldView;
