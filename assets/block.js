(function (blocks, element, components, blockEditor) {
    const el = element.createElement;
    const { TextControl, PanelBody } = components;
    const { InspectorControls } = blockEditor;

    blocks.registerBlockType('aslan/pay', {
        title: 'Aslan Pay Button',
        icon: 'cart',
        category: 'widgets',
        attributes: {
            amount:      { type: 'number', default: 0 },
            description: { type: 'string', default: '' },
            button_text: { type: 'string', default: '' },
            success_url: { type: 'string', default: '' },
            cancel_url:  { type: 'string', default: '' },
            reference:   { type: 'string', default: '' },
        },
        edit: function (props) {
            const a = props.attributes;
            const set = function (k) { return function (v) { const u = {}; u[k] = v; props.setAttributes(u); }; };
            return el('div', { className: props.className },
                el(InspectorControls, {},
                    el(PanelBody, { title: 'Aslan Pay' },
                        el(TextControl, { label: 'Amount (centimes, multiple of 100)', type: 'number', value: a.amount, onChange: function (v) { set('amount')(parseInt(v || '0', 10)); } }),
                        el(TextControl, { label: 'Description', value: a.description, onChange: set('description') }),
                        el(TextControl, { label: 'Button text', value: a.button_text, onChange: set('button_text') }),
                        el(TextControl, { label: 'Success URL', value: a.success_url, onChange: set('success_url') }),
                        el(TextControl, { label: 'Cancel URL', value: a.cancel_url, onChange: set('cancel_url') }),
                        el(TextControl, { label: 'Reference', value: a.reference, onChange: set('reference') })
                    )
                ),
                el('div', { style: { border: '1px dashed #999', padding: '12px' } },
                    el('strong', null, 'Aslan Pay Button'),
                    el('div', null, a.amount ? ('Pay ' + (a.amount / 100) + ' MAD') : 'Set an amount in the sidebar.')
                )
            );
        },
        save: function () { return null; },
    });
}(window.wp.blocks, window.wp.element, window.wp.components, window.wp.blockEditor));
