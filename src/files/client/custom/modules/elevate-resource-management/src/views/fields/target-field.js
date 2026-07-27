import EnumFieldView from 'views/fields/enum';

const SCALAR_TYPES = [
    'varchar',
    'text',
    'enum',
    'multiEnum',
    'int',
    'float',
    'autoincrement',
    'bool',
    'date',
    'datetime',
    'datetimeOptional',
];

class TargetFieldView extends EnumFieldView {
    setup() {
        this.listenTo(
            this.model,
            'change:targetEntityType',
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
        const entityDefs = entityType ?
            this.getMetadata().get(`entityDefs.${entityType}`) ?? {} :
            {};
        const fields = entityDefs.fields ?? {};
        const links = entityDefs.links ?? {};
        const kind = this.model.getFieldParam(this.name, 'mappingKind') ??
            this.params.mappingKind ??
            'scalar';
        const optional = ['account', 'contact'].includes(kind);
        const items = Object.entries(fields)
            .filter(([field, defs]) => this.isAllowed(field, defs, links, kind))
            .map(([field]) => ({
                value: field,
                label: `${this.translate(field, 'fields', entityType)} (${field})`,
            }))
            .sort((a, b) => a.label.localeCompare(b.label));
        const options = items.map(item => item.value);
        const translations = Object.fromEntries(
            items.map(item => [item.value, item.label]),
        );

        options.unshift('');
        translations[''] = optional ? 'None' : 'Select a field';

        return {options, translations};
    }

    isAllowed(field, defs, links, kind) {
        if (defs.disabled || defs.utility) {
            return false;
        }

        if (kind === 'scalar') {
            return SCALAR_TYPES.includes(defs.type);
        }

        if (kind === 'status') {
            return defs.type === 'enum' && Array.isArray(defs.options);
        }

        const target = {
            resource: 'User',
            account: 'Account',
            contact: 'Contact',
        }[kind];
        const allowedTypes = kind === 'resource' ?
            ['link', 'linkMultiple'] :
            ['link'];

        const linkName = typeof defs.link === 'string' ? defs.link : field;
        const linkedEntity = defs.entity ??
            links[linkName]?.entity ??
            links[field]?.entity;

        return Boolean(
            target &&
            allowedTypes.includes(defs.type) &&
            linkedEntity === target
        );
    }
}

export default TargetFieldView;
