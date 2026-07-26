import Controller from 'controller';

export default class extends Controller {
    actionIndex(options) {
        this.main('elevate-resource-management:views/workspace', {
            tab: options.tab || 'capacity',
            targetType: options.targetType,
            targetId: options.targetId,
        });
    }
}
