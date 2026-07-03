export const STORAGE_KEY = 'hubroute-demo-state-v5';
export const DEMO_ACCOUNTS = [
  { email: 'pickuphub@hubroute.local', password: 'hub1234', hubId: 'hub-north', role: 'Pickup hub operator' },
  { email: 'sortation@hubroute.local', password: 'hub1234', hubId: 'hub-central', role: 'Sortation hub operator' },
  { email: 'ctghub@hubroute.local', password: 'hub1234', hubId: 'hub-east', role: 'Chattogram delivery operator' },
  { email: 'savarhub@hubroute.local', password: 'hub1234', hubId: 'hub-savar', role: 'Savar return hub operator' }
];
export const STATUSES = ['requested','assigned','picked_up','in_transit','in_warehouse','out_for_delivery','delivered','failed'];
export const EVENT_TYPES = ['requested','assigned','picked_up','handoff_departed','handoff_received','in_transit','in_warehouse','out_for_delivery','delivered','failed','payment_collected','note'];
export const ACTIVE_STATUSES = ['requested','assigned','picked_up','in_transit','in_warehouse','out_for_delivery'];

export function seedState(){
  const now = Date.now();
  const ago = (minutes) => new Date(now - minutes * 60000).toISOString();
  return {
    version: 5,
    currentHubId: 'hub-north',
    hubs: [
      {id:'hub-north', name:'Dhaka North Pickup Hub', city:'Dhaka', type:'pickup'},
      {id:'hub-central', name:'Tejgaon Sortation Hub', city:'Dhaka', type:'warehouse'},
      {id:'hub-east', name:'Chattogram Delivery Hub', city:'Chattogram', type:'delivery'},
      {id:'hub-savar', name:'Savar Return Hub', city:'Savar', type:'return'}
    ],
    customers: [
      {id:'cust-maya', name:'Maya Fashion', phone:'+8801711001001', email:'ops@maya-fashion.example', address:'House 14, Road 11, Banani, Dhaka 1213', status:'active'},
      {id:'cust-bongo', name:'Bongo Mart', phone:'+8801812002002', email:'dispatch@bongomart.example', address:'Plot 22, Section 11, Mirpur, Dhaka 1216', status:'active'},
      {id:'cust-chashi', name:'Chashi Bazaar', phone:'+8801913003003', email:'orders@chashi.example', address:'Board Bazar, Gazipur 1704', status:'active'},
      {id:'cust-book', name:'Book Bazar BD', phone:'+8801614004004', email:'fulfilment@bookbazar.example', address:'Elephant Road, Dhaka 1205', status:'active'},
      {id:'cust-tech', name:'Tech Ghor', phone:'+8801515005005', email:'warehouse@techghor.example', address:'Bashundhara R/A, Dhaka 1229', status:'active'},
      {id:'cust-port', name:'Port City Traders', phone:'+8801316006006', email:'ops@portcity.example', address:'Agrabad C/A, Chattogram 4100', status:'active'}
    ],
    riders: [
      {id:'rider-amina', name:'Amina Rahman', hubId:'hub-north', phone:'+8801711223344', vehicle:'Motorbike', capacity:24, status:'active'},
      {id:'rider-karim', name:'Karim Hossain', hubId:'hub-north', phone:'+8801811223344', vehicle:'Covered van', capacity:48, status:'active'},
      {id:'rider-nusrat', name:'Nusrat Jahan', hubId:'hub-north', phone:'+8801911223344', vehicle:'Motorbike', capacity:22, status:'active'},
      {id:'rider-rafiq', name:'Rafiq Islam', hubId:'hub-savar', phone:'+8801611223344', vehicle:'Pickup van', capacity:36, status:'active'},
      {id:'rider-jamal', name:'Jamal Uddin', hubId:'hub-central', phone:'+8801711778899', vehicle:'3-ton truck', capacity:80, status:'active'},
      {id:'rider-mika', name:'Mika Roy', hubId:'hub-east', phone:'+8801511223344', vehicle:'Motorbike', capacity:18, status:'active'}
    ],
    routes: [
      {id:'route-banani-gulshan', name:'Banani-Gulshan Pickup', hubId:'hub-north', type:'pickup', areas:'Banani, Gulshan, Mohakhali DOHS, Niketon', capacity:24, riderId:'rider-amina', status:'active'},
      {id:'route-mirpur-uttara', name:'Mirpur-Uttara Pickup', hubId:'hub-north', type:'pickup', areas:'Mirpur, Uttara, Pallabi, Airport Road', capacity:22, riderId:'rider-nusrat', status:'active'},
      {id:'route-dhanmondi-loop', name:'Dhanmondi-Mohammadpur Loop', hubId:'hub-north', type:'pickup', areas:'Dhanmondi, Mohammadpur, Kalabagan, New Market', capacity:18, riderId:'rider-amina', status:'active'},
      {id:'route-dhaka-bulk', name:'Dhaka Bulk Merchant Van', hubId:'hub-north', type:'pickup', areas:'Tejgaon, Motijheel, Karwan Bazar, Bashundhara', capacity:48, riderId:'rider-karim', status:'active'},
      {id:'route-tejgaon-sort', name:'Tejgaon Cross-Dock Sort Line', hubId:'hub-central', type:'warehouse', areas:'Dhaka inbound, Narayanganj, Savar, Gazipur, Chattogram trunk', capacity:80, riderId:'rider-jamal', status:'active'},
      {id:'route-dhaka-ctg-line', name:'Dhaka-Chattogram Trunk Line', hubId:'hub-central', type:'warehouse', areas:'Tejgaon, Cumilla transfer, Chattogram inbound', capacity:64, riderId:'rider-jamal', status:'active'},
      {id:'route-savar-return', name:'Savar-Gazipur Return Run', hubId:'hub-savar', type:'return', areas:'Savar, Ashulia, Board Bazar, Tongi', capacity:36, riderId:'rider-rafiq', status:'active'},
      {id:'route-ctg-lastmile', name:'Chattogram Last Mile Loop', hubId:'hub-east', type:'delivery', areas:'Agrabad, Halishahar, Nasirabad, GEC', capacity:18, riderId:'rider-mika', status:'active'}
    ],
    parcels: [
      {id:'parcel-1', code:'HR260703DHK1A2', customerId:'cust-maya', pickupAddress:'House 14, Road 11, Banani, Dhaka 1213', dropoffAddress:'Flat 5B, Road 27, Dhanmondi, Dhaka 1209', amountCents:129900, cod:true, status:'assigned', currentHubId:'hub-north', pendingToHubId:'', pickupHubId:'hub-north', warehouseHubId:'hub-central', deliveryHubId:'hub-north', pathIndex:0, routeId:'route-banani-gulshan', riderId:'rider-amina', weightKg:'1.2', notes:'Boutique clothing, handle clean packaging'},
      {id:'parcel-2', code:'HR260704UTR7M5', customerId:'cust-bongo', pickupAddress:'Plot 22, Section 11, Mirpur, Dhaka 1216', dropoffAddress:'Sector 7, Uttara, Dhaka 1230', amountCents:0, cod:false, status:'picked_up', currentHubId:'hub-north', pendingToHubId:'', pickupHubId:'hub-north', warehouseHubId:'hub-central', deliveryHubId:'hub-north', pathIndex:0, routeId:'route-mirpur-uttara', riderId:'rider-nusrat', weightKg:'0.8', notes:'Prepaid marketplace parcel'},
      {id:'parcel-3', code:'HR260705GAZ4P9', customerId:'cust-chashi', pickupAddress:'Board Bazar, Gazipur 1704', dropoffAddress:'Banasree Block C, Dhaka 1219', amountCents:85000, cod:true, status:'requested', currentHubId:'hub-savar', pendingToHubId:'', pickupHubId:'hub-savar', warehouseHubId:'hub-central', deliveryHubId:'hub-north', pathIndex:0, routeId:'', riderId:'', weightKg:'4.4', notes:'Fresh produce, pickup before noon'},
      {id:'parcel-4', code:'HR260706DHM6V2', customerId:'cust-book', pickupAddress:'Elephant Road, Dhaka 1205', dropoffAddress:'House 8, Road 3, Mohammadpur, Dhaka 1207', amountCents:45000, cod:true, status:'requested', currentHubId:'hub-north', pendingToHubId:'', pickupHubId:'hub-north', warehouseHubId:'hub-central', deliveryHubId:'hub-north', pathIndex:0, routeId:'', riderId:'', weightKg:'2.1', notes:'Books, COD cash on delivery'},
      {id:'parcel-5', code:'HR260707BSH8K1', customerId:'cust-tech', pickupAddress:'Bashundhara R/A, Dhaka 1229', dropoffAddress:'Motijheel C/A, Dhaka 1000', amountCents:245000, cod:true, status:'assigned', currentHubId:'hub-north', pendingToHubId:'', pickupHubId:'hub-north', warehouseHubId:'hub-central', deliveryHubId:'hub-north', pathIndex:0, routeId:'route-dhaka-bulk', riderId:'rider-karim', weightKg:'5.0', notes:'Electronics, signature required'},
      {id:'parcel-6', code:'HR260708NAR2B6', customerId:'cust-maya', pickupAddress:'Gulshan 1, Dhaka 1212', dropoffAddress:'Chashara, Narayanganj 1400', amountCents:179000, cod:true, status:'in_warehouse', currentHubId:'hub-central', pendingToHubId:'', pickupHubId:'hub-north', warehouseHubId:'hub-central', deliveryHubId:'hub-north', pathIndex:1, routeId:'route-tejgaon-sort', riderId:'rider-jamal', weightKg:'1.7', notes:'Received at Tejgaon and ready for outbound hub handoff'},
      {id:'parcel-7', code:'HR260709CTG3C8', customerId:'cust-port', pickupAddress:'Agrabad C/A, Chattogram 4100', dropoffAddress:'GEC Circle, Chattogram 4000', amountCents:92000, cod:true, status:'out_for_delivery', currentHubId:'hub-east', pendingToHubId:'', pickupHubId:'hub-east', warehouseHubId:'hub-central', deliveryHubId:'hub-east', pathIndex:2, routeId:'route-ctg-lastmile', riderId:'rider-mika', weightKg:'1.0', notes:'Chattogram last mile'},
      {id:'parcel-8', code:'HR260710SVR5H3', customerId:'cust-chashi', pickupAddress:'Ashulia, Savar 1341', dropoffAddress:'Tongi Station Road, Gazipur 1710', amountCents:0, cod:false, status:'failed', currentHubId:'hub-savar', pendingToHubId:'', pickupHubId:'hub-savar', warehouseHubId:'hub-central', deliveryHubId:'hub-savar', pathIndex:2, routeId:'route-savar-return', riderId:'rider-rafiq', weightKg:'3.5', notes:'Recipient unreachable, retry scheduled'},
      {id:'parcel-9', code:'HR260711DHK9T4', customerId:'cust-tech', pickupAddress:'Kuril, Dhaka 1229', dropoffAddress:'Kaptai Road, Chattogram 4212', amountCents:319000, cod:true, status:'in_transit', currentHubId:'hub-north', pendingToHubId:'hub-central', pickupHubId:'hub-north', warehouseHubId:'hub-central', deliveryHubId:'hub-east', pathIndex:0, routeId:'route-dhaka-bulk', riderId:'rider-karim', weightKg:'6.2', notes:'Departed Dhaka North for Tejgaon sortation'},
      {id:'parcel-10', code:'HR260712CTG4L7', customerId:'cust-port', pickupAddress:'Tejgaon I/A, Dhaka 1208', dropoffAddress:'Halishahar, Chattogram 4216', amountCents:154000, cod:true, status:'in_transit', currentHubId:'hub-central', pendingToHubId:'hub-east', pickupHubId:'hub-north', warehouseHubId:'hub-central', deliveryHubId:'hub-east', pathIndex:1, routeId:'route-dhaka-ctg-line', riderId:'rider-jamal', weightKg:'2.4', notes:'Outbound line haul to Chattogram hub'}
    ],
    events: [
      {id:'evt-1', parcelId:'parcel-1', type:'requested', hubId:'hub-north', note:'Banani merchant request created', at:ago(260)},
      {id:'evt-2', parcelId:'parcel-1', type:'assigned', hubId:'hub-north', note:'Assigned to Banani-Gulshan Pickup and Amina Rahman', at:ago(240)},
      {id:'evt-3', parcelId:'parcel-2', type:'requested', hubId:'hub-north', note:'Mirpur marketplace parcel created', at:ago(220)},
      {id:'evt-4', parcelId:'parcel-2', type:'assigned', hubId:'hub-north', note:'Assigned to Mirpur-Uttara Pickup and Nusrat Jahan', at:ago(205)},
      {id:'evt-5', parcelId:'parcel-2', type:'picked_up', hubId:'hub-north', note:'Pickup scan recorded at Mirpur', at:ago(172)},
      {id:'evt-6', parcelId:'parcel-3', type:'requested', hubId:'hub-savar', note:'Gazipur produce pickup waiting for route', at:ago(140)},
      {id:'evt-7', parcelId:'parcel-4', type:'requested', hubId:'hub-north', note:'Dhanmondi COD parcel created', at:ago(118)},
      {id:'evt-8', parcelId:'parcel-5', type:'assigned', hubId:'hub-north', note:'Bulk van assigned for Bashundhara pickup', at:ago(91)},
      {id:'evt-9', parcelId:'parcel-6', type:'in_warehouse', hubId:'hub-central', note:'Received at Tejgaon Sortation Hub', at:ago(74)},
      {id:'evt-10', parcelId:'parcel-7', type:'out_for_delivery', hubId:'hub-east', note:'Out for delivery in Chattogram', at:ago(43)},
      {id:'evt-11', parcelId:'parcel-8', type:'failed', hubId:'hub-savar', note:'Recipient unreachable in Tongi', at:ago(31)},
      {id:'evt-12', parcelId:'parcel-9', type:'handoff_departed', hubId:'hub-north', note:'Departed Dhaka North Pickup Hub for Tejgaon Sortation Hub', at:ago(24)},
      {id:'evt-13', parcelId:'parcel-10', type:'handoff_departed', hubId:'hub-central', note:'Departed Tejgaon Sortation Hub for Chattogram Delivery Hub', at:ago(18)}
    ]
  };
}
