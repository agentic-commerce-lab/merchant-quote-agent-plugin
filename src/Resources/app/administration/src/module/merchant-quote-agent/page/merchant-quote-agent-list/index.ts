import template from './merchant-quote-agent-list.html.twig';

Shopware.Component.register('merchant-quote-agent-list', {
    template,

    inject: ['acl'],

    metaInfo() {
        return {
            title: this.$createTitle(),
        };
    },
});
