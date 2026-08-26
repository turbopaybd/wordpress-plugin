const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
const { createElement } = window.wp.element;

// PHP থেকে ডেটা রিড করা হচ্ছে
const paymentData = window.wc.wcSettings.getSetting('paymentMethodData', {})['turbopaybd_gateway'] || {};

// চেকআউট তালিকার টাইটেল নির্ধারণ
const label = createElement(
    'span',
    { style: { display: 'flex', alignItems: 'center', fontWeight: '600' } },
    paymentData.title || 'TurboPay BD'
);

// ডেসক্রিপশন টেক্সট এবং তার ঠিক নিচে কম্বাইনড ব্যানার ইমেজটি রেসপন্সিভলি রেন্ডার করা হচ্ছে
const content = () => createElement(
    'div', 
    { style: { paddingLeft: '20px', paddingTop: '10px' } }, 
    createElement('p', { style: { margin: '0 0 12px 0', color: '#555', fontSize: '14px', lineHeight: '1.5' } }, paymentData.description || ''),
    paymentData.icon && createElement('img', { 
        src: paymentData.icon, 
        alt: 'TurboPay BD All Payment Gateways', 
        style: { display: 'block', width: '100%', maxWidth: '600px', height: 'auto', marginTop: '12px', borderRadius: '4px' } 
    })
);

// গুটেনবার্গ এডিটর মোডের জন্য প্রিভিউ লেআউট
const edit = () => createElement(
    'div', 
    null, 
    createElement('p', { style: { margin: '0 0 8px 0' } }, paymentData.description || ''),
    paymentData.icon && createElement('img', { src: paymentData.icon, alt: 'Preview', style: { width: '100%', maxWidth: '600px', height: 'auto' } })
);

registerPaymentMethod({
    name: 'turbopaybd_gateway',
    label: label,
    content: createElement(content),
    edit: createElement(edit),
    canMakePayment: () => true,
    ariaLabel: paymentData.title || 'TurboPay BD',
    supports: paymentData.supports || ['products'],
});