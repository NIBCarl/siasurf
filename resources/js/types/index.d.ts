export interface User {
    id: number;
    name: string;
    email: string;
    email_verified_at?: string;
    role: 'student' | 'instructor' | 'admin';
    phone?: string;
    avatar_path?: string | null;
    avatar_url?: string | null;
    created_at?: string;
    instructor_profile?: {
        bio: string;
        level: number;
        offered_services?: string[] | null;
        status: string;
        rate_per_hour: number | null;
        guiding_rate?: number | null;
        coaching_rate?: number | null;
        strike_count: number;
        qr_code_path: string;
        surf_spots?: Array<{
            id: number;
            name: string;
            area?: string | null;
            difficulty: string;
        }>;
    };
    student_profile?: {
        skill_level: string;
        skill_assessed_at?: string | null;
        is_first_time: boolean;
        birthday?: string | null;
        age?: number | null;
        country?: string | null;
        status?: string;
    };
    skill_upgrade_requests?: Array<{
        id: number;
        requested_level: string;
        status: string;
    }>;
}

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    auth: {
        user: User;
    };
    flash: {
        success?: string;
        error?: string;
        info?: string;
        tideWarning?: string;
    };
};

export type WaiverType = 'liability' | 'parental_consent';

export interface Booking {
    id: number;
    date: string;
    time_period: 'morning' | 'afternoon';
    status: string;
    skill_level: string;
    student_age: number;
    needs_hotel_pickup?: boolean;
    hotel_name?: string | null;
    instructor: {
        id: number;
        name: string;
    };
    surf_spot: {
        id: number;
        name: string;
    };
}
