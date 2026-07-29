import EditView from 'views/edit';

export default class extends EditView {
    getHeader() {
        if (!this.model.isNew()) {
            return super.getHeader();
        }

        const link = (label, href) => {
            const element = document.createElement('a');
            element.href = href;
            element.textContent = label;
            return element;
        };
        const text = label => {
            const element = document.createElement('span');
            element.textContent = label;
            element.style.userSelect = 'none';
            return element;
        };

        return this.buildHeaderHtml([
            link(
                this.translate(
                    'timeManagement',
                    'labels',
                    'ElevateResourceManagement'
                ),
                '#ElevateResourceManagement/my-work'
            ),
            link(
                this.translate('library', 'labels', 'ElevateResourceManagement'),
                '#ElevateResourceManagement/library'
            ),
            text(this.translate('workItems', 'labels', 'ElevateResourceManagement')),
            text(this.translate('create')),
        ]);
    }
}
