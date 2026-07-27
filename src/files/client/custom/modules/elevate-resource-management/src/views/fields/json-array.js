import BaseFieldView from 'views/fields/base';

class JsonArrayFieldView extends BaseFieldView {
    type = 'jsonArray';

    listTemplateContent = `
        {{#if valueIsSet}}
            <code>{{value}}</code>
        {{else}}
            <span class="loading-value"></span>
        {{/if}}
    `;

    detailTemplateContent = `
        {{#if valueIsSet}}
            <pre class="elevate-rm-json-array-value">{{value}}</pre>
        {{else}}
            <span class="loading-value"></span>
        {{/if}}
    `;

    editTemplateContent = `
        <textarea
            class="form-control"
            data-name="{{name}}"
            rows="6"
            spellcheck="false"
            aria-label="{{labelText}}"
        >{{value}}</textarea>
        <p class="help-block">Enter a valid JSON array.</p>
    `;

    data() {
        const data = super.data();
        const value = this.model.get(this.name);

        data.valueIsSet = value !== undefined;
        data.labelText = this.getLabelText();
        data.value = value === undefined ? '' : JSON.stringify(value ?? [], null, 2);

        return data;
    }

    fetch() {
        const parsed = this.parseInput();

        return {
            [this.name]: parsed.valid ? parsed.value : this.model.get(this.name),
        };
    }

    validate() {
        const parsed = this.parseInput();

        if (!parsed.valid) {
            this.showValidationMessage(
                parsed.message,
                `[data-name="${this.name}"]`,
            );

            return true;
        }

        return super.validate();
    }

    parseInput() {
        const element = this.$el.find(`[data-name="${this.name}"]`);

        if (!element.length) {
            return {valid: true, value: this.model.get(this.name) ?? []};
        }

        const input = String(element.val() ?? '').trim();

        if (!input) {
            return {valid: true, value: []};
        }

        try {
            const value = JSON.parse(input);

            if (!Array.isArray(value)) {
                return {valid: false, message: 'The value must be a JSON array.'};
            }

            return {valid: true, value};
        } catch (error) {
            return {
                valid: false,
                message: `Invalid JSON: ${error.message}`,
            };
        }
    }
}

export default JsonArrayFieldView;
