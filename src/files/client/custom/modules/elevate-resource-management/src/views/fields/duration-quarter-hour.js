import BaseFieldView from 'views/fields/base';

class DurationQuarterHourFieldView extends BaseFieldView {
    type = 'int';

    listTemplateContent = `{{displayValue}}`;
    detailTemplateContent = `
        <span>{{displayValue}}</span>
        {{#if legacy}}<p class="text-warning small">{{legacyMessage}}</p>{{/if}}
    `;
    editTemplateContent = `
        <div class="elevate-rm-duration" data-name="{{name}}">
            <label class="sr-only" for="{{name}}-hours">Hours</label>
            <select id="{{name}}-hours" class="form-control" data-part="hours">
                {{#each hourOptions}}<option value="{{value}}" {{#if selected}}selected{{/if}}>{{label}}</option>{{/each}}
            </select>
            <span aria-hidden="true">h</span>
            <label class="sr-only" for="{{name}}-minutes">Minutes</label>
            <select id="{{name}}-minutes" class="form-control" data-part="minutes">
                {{#each minuteOptions}}<option value="{{value}}" {{#if selected}}selected{{/if}}>{{label}}</option>{{/each}}
            </select>
            <span aria-hidden="true">m</span>
        </div>
        {{#if legacy}}<p class="help-block text-warning">{{legacyMessage}}</p>{{/if}}
    `;

    data() {
        const data = super.data();
        const seconds = Number(this.model.get(this.name) || 0);
        const hours = Math.floor(seconds / 3600);
        const remainder = seconds % 3600;
        const minutes = Math.floor(remainder / 60);
        const legacy = seconds > 0 && (
            seconds < 900 ||
            seconds > 86400 ||
            seconds % 900 !== 0
        );

        const hourOptions = Array.from({length: 25}, (_, value) => ({
            value,
            label: String(value).padStart(2, '0'),
            selected: value === hours,
        }));
        const minuteOptions = [0, 15, 30, 45].map(value => ({
            value,
            label: String(value).padStart(2, '0'),
            selected: !legacy && value === minutes,
        }));

        if (legacy) {
            minuteOptions.unshift({
                value: 'legacy',
                label: `${String(minutes).padStart(2, '0')} (legacy)`,
                selected: true,
            });
        }

        return {
            ...data,
            hourOptions,
            minuteOptions,
            legacy,
            legacyMessage: this.translate('legacyEstimate', 'messages', 'ElevateRmWorkItem'),
            displayValue: this.display(seconds),
        };
    }

    afterRender() {
        super.afterRender();
        const $hours = this.$el.find('[data-part="hours"]');
        const $minutes = this.$el.find('[data-part="minutes"]');

        $hours.on('change', () => {
            if ($minutes.val() === 'legacy') {
                $minutes.val('0');
            }
            if (Number($hours.val()) === 24) {
                $minutes.val('0');
            }
            this.trigger('change');
        });
        $minutes.on('change', () => {
            if (Number($hours.val()) === 24 && Number($minutes.val()) > 0) {
                $hours.val('23');
            }
            this.trigger('change');
        });
    }

    fetch() {
        const minutes = this.$el.find('[data-part="minutes"]').val();

        if (minutes === 'legacy') {
            return {[this.name]: this.model.get(this.name)};
        }

        const hours = Number(this.$el.find('[data-part="hours"]').val() || 0);
        const minuteValue = Number(minutes || 0);

        return {[this.name]: hours * 3600 + minuteValue * 60};
    }

    validate() {
        if (this.$el.find('[data-part="minutes"]').val() === 'legacy') {
            return false;
        }

        const value = Number(this.fetch()[this.name] || 0);

        if (value < 900 || value > 86400 || value % 900 !== 0) {
            this.showValidationMessage(
                'Choose a duration from 15 minutes through 24 hours.',
                `[data-name="${this.name}"]`
            );
            return true;
        }

        return false;
    }

    display(seconds) {
        if (!seconds) {
            return '—';
        }

        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor(seconds % 3600 / 60);

        return `${hours}h ${String(minutes).padStart(2, '0')}m`;
    }
}

export default DurationQuarterHourFieldView;
