import { Head } from '@inertiajs/react';
import PortalLayout from '../../../Layouts/PortalLayout';
import PageHeader from '../../../Components/PageHeader';
import ClientFields from '../../../Components/client/ClientFields';
import ContactCards from '../../../Components/client/ContactCards';

// The company's details (as on the staff client page) and its people,
// read-only.
export default function PortalContactsIndex({ company, contacts }) {
    return (
        <PortalLayout>
            <Head title="Contacts" />
            <PageHeader title={company.name} />
            <ClientFields company={company} />
            <ContactCards contacts={contacts} />
        </PortalLayout>
    );
}
