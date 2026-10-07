import {
    AirplaneTilt,
    AppWindow,
    Bank,
    BookOpen,
    Buildings,
    Calculator,
    Camera,
    Car,
    ForkKnife,
    Globe,
    GraduationCap,
    Laptop,
    Lightning,
    Megaphone,
    Package,
    PaintBrush,
    Paperclip,
    Phone,
    Printer,
    Scales,
    ShieldCheck,
    Tag,
    UsersThree,
    Wrench,
} from '@phosphor-icons/react';

// Expense categories are told apart by icon, not color -- the app has one
// hue. Categories are free-form names, so the icon comes from the first
// keyword the name contains; anything unmatched gets the generic tag.
const RULES = [
    [/software|subscription|saas|\bapps?\b|licen[cs]e/, AppWindow],
    [/equipment|hardware|computer|laptop|electronic|devices/, Laptop],
    [/camera|photo|video/, Camera],
    [/travel|flight|airfare|hotel|lodging|accommodation|taxi|transportation/, AirplaneTilt],
    [/\bcars?\b|mileage|fuel|\bgas\b|parking|vehicle/, Car],
    [/meal|food|dining|restaurant|lunch|coffee/, ForkKnife],
    [/office|supplies|stationery/, Paperclip],
    [/print|business cards?/, Printer],
    [/contractor|freelance|subcontract|staff|payroll|wage|labor|officer/, UsersThree],
    [/advertis|marketing|promo|ads\b/, Megaphone],
    [/hosting|domain|internet|web/, Globe],
    [/phone|mobile|cell/, Phone],
    [/utilit|electric|power/, Lightning],
    [/\brent|lease|studio space|cowork/, Buildings],
    [/train|course|education|conference|workshop/, GraduationCap],
    [/accounting|bookkeeping|\btax/, Calculator],
    [/\bbooks?\b|research|reference/, BookOpen],
    [/bank|\bfees?\b|interest|merchant/, Bank],
    [/legal|attorney|lawyer/, Scales],
    [/insurance/, ShieldCheck],
    [/shipping|postage|courier|freight/, Package],
    [/repair|maintenance|tool/, Wrench],
    [/design|\bart\b|artwork|stock/, PaintBrush],
];

export function expenseCategoryIcon(name) {
    const normalized = String(name ?? '').toLowerCase();
    const match = RULES.find(([pattern]) => pattern.test(normalized));
    return match ? match[1] : Tag;
}
