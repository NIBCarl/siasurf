export interface User {
    id: number;
    name: string;
    email: string;
    email_verified_at?: string;
    role: 'student' | 'instructor' | 'admin';
    phone?: string;
    avatar_path?: string;
    instructor_profile?: {
        bio: string;
        level: number;
        status: string;
        rate_per_hour: number;
        strike_count: number;
        qr_code_path: string;
    };
    student_profile?: {
        skill_level: string;
        is_first_time: boolean;
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
};

export type WaiverType = 'liability' | 'parental_consent';

export interface Booking {
    id: number;
    date: string;
    time_period: 'morning' | 'afternoon';
    status: string;
    skill_level: string;
    student_age: number;
    instructor: {
        id: number;
        name: string;
    };
    surf_spot: {
        id: number;
        name: string;
    };
}
