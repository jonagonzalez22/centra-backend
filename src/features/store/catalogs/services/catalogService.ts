import axios from 'axios';

export interface DocumentType {
    id: string;
    code: string;
    name: string;
}

interface ApiResponse<T> {
    status: string;
    message: string;
    data: T;
    errors: null | Record<string, string[]>;
}

interface DocumentTypesData {
    items: DocumentType[];
}

export async function getDocumentTypes(): Promise<DocumentType[]> {
    const response = await axios.get<ApiResponse<DocumentTypesData>>(
        '/api/v1/catalogs/document-types'
    );

    return response.data.data.items;
}
