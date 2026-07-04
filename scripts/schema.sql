--
-- PostgreSQL database dump
--

\restrict kfP73bQa6f3avcKlCA98krvdkvEGjjRPtrQ71MK2HUheyOBJrjReHZspQIBnpSD

-- Dumped from database version 16.14 (Debian 16.14-1.pgdg12+1)
-- Dumped by pg_dump version 16.14 (Debian 16.14-1.pgdg12+1)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: pgcrypto; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS pgcrypto WITH SCHEMA public;


--
-- Name: EXTENSION pgcrypto; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION pgcrypto IS 'cryptographic functions';


--
-- Name: uuid-ossp; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS "uuid-ossp" WITH SCHEMA public;


--
-- Name: EXTENSION "uuid-ossp"; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION "uuid-ossp" IS 'generate universally unique identifiers (UUIDs)';


--
-- Name: vector; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS vector WITH SCHEMA public;


--
-- Name: EXTENSION vector; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION vector IS 'vector data type and ivfflat and hnsw access methods';


--
-- Name: update_updated_at(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.update_updated_at() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN NEW.updated_at = NOW(); RETURN NEW; END;
$$;


SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: agents; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.agents (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    name character varying(120) NOT NULL,
    voice_id character varying(100),
    llm_model character varying(60) DEFAULT 'gpt-4o-mini'::character varying NOT NULL,
    system_prompt text DEFAULT ''::text NOT NULL,
    language character varying(10) DEFAULT 'es-MX'::character varying NOT NULL,
    channel character varying(30) DEFAULT 'voice'::character varying NOT NULL,
    phone_number character varying(30),
    whatsapp_number character varying(30),
    is_active boolean DEFAULT true NOT NULL,
    config jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: appointment_reminders; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.appointment_reminders (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    appointment_id uuid NOT NULL,
    tenant_id uuid NOT NULL,
    reminder_type character varying(40) NOT NULL,
    channel character varying(20) DEFAULT 'whatsapp'::character varying NOT NULL,
    status character varying(20) DEFAULT 'sent'::character varying NOT NULL,
    message_sid character varying(100),
    sent_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: appointments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.appointments (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    conversation_id uuid,
    lead_id uuid,
    title character varying(200),
    scheduled_at timestamp with time zone NOT NULL,
    duration_mins integer DEFAULT 60 NOT NULL,
    location text,
    notes text,
    status character varying(30) DEFAULT 'pending'::character varying NOT NULL,
    external_ref character varying(200),
    external_source character varying(60),
    reminder_sent boolean DEFAULT false NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    service_type_id uuid,
    doctor_id uuid,
    is_urgency boolean DEFAULT false NOT NULL,
    deposit_status character varying(30) DEFAULT 'none'::character varying NOT NULL,
    deposit_payment_link text,
    deposit_payment_intent character varying(200),
    deposit_amount numeric(10,2),
    patient_name character varying(200),
    patient_phone character varying(30),
    reminder_24h_sent boolean DEFAULT false NOT NULL,
    reminder_2h_sent boolean DEFAULT false NOT NULL,
    post_instr_sent boolean DEFAULT false NOT NULL,
    confirmation_status character varying(20) DEFAULT 'pending'::character varying NOT NULL,
    confirmation_requested_at timestamp with time zone,
    confirmed_at timestamp with time zone,
    cancelled_at timestamp with time zone,
    deposit_currency character varying(3) DEFAULT 'mxn'::character varying NOT NULL,
    deposit_paid_at timestamp with time zone,
    deposit_checkout_session character varying(255),
    CONSTRAINT appointments_confirmation_status_check CHECK (((confirmation_status)::text = ANY ((ARRAY['pending'::character varying, 'confirmed'::character varying, 'cancelled'::character varying, 'no_response'::character varying])::text[])))
);


--
-- Name: audit_log; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.audit_log (
    id bigint NOT NULL,
    tenant_id uuid,
    user_id uuid,
    action character varying(100) NOT NULL,
    resource_type character varying(60),
    resource_id uuid,
    ip_address inet,
    metadata jsonb,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: audit_log_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.audit_log_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: audit_log_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.audit_log_id_seq OWNED BY public.audit_log.id;


--
-- Name: campaign_contacts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.campaign_contacts (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    campaign_id uuid NOT NULL,
    tenant_id uuid NOT NULL,
    lead_id uuid,
    name character varying(120),
    phone character varying(30) NOT NULL,
    email character varying(254),
    custom_data jsonb DEFAULT '{}'::jsonb NOT NULL,
    status character varying(20) DEFAULT 'pending'::character varying NOT NULL,
    attempts integer DEFAULT 0 NOT NULL,
    last_attempt_at timestamp with time zone,
    next_attempt_at timestamp with time zone,
    outcome character varying(50),
    outcome_data jsonb,
    conversation_id uuid,
    priority integer DEFAULT 0 NOT NULL,
    locked_until timestamp with time zone,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: campaigns; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.campaigns (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    agent_id uuid NOT NULL,
    name character varying(200) NOT NULL,
    description text,
    channel character varying(20) DEFAULT 'voice'::character varying NOT NULL,
    trigger_type character varying(20) DEFAULT 'manual'::character varying NOT NULL,
    status character varying(20) DEFAULT 'draft'::character varying NOT NULL,
    script text NOT NULL,
    goal character varying(100),
    wa_template_name character varying(100),
    wa_template_lang character varying(10) DEFAULT 'es_MX'::character varying,
    allowed_hours_start integer DEFAULT 9 NOT NULL,
    allowed_hours_end integer DEFAULT 19 NOT NULL,
    allowed_days integer[] DEFAULT ARRAY[1, 2, 3, 4, 5] NOT NULL,
    trigger_config jsonb DEFAULT '{}'::jsonb NOT NULL,
    calls_per_hour integer DEFAULT 10 NOT NULL,
    max_attempts integer DEFAULT 2 NOT NULL,
    total_contacts integer DEFAULT 0 NOT NULL,
    contacted integer DEFAULT 0 NOT NULL,
    converted integer DEFAULT 0 NOT NULL,
    failed integer DEFAULT 0 NOT NULL,
    scheduled_at timestamp with time zone,
    started_at timestamp with time zone,
    completed_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: consultorio_session_types; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.consultorio_session_types (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    professional_ids uuid[] DEFAULT '{}'::uuid[],
    name character varying(100) NOT NULL,
    slug character varying(60) NOT NULL,
    description text,
    duration_mins integer DEFAULT 60 NOT NULL,
    session_count integer DEFAULT 1 NOT NULL,
    frequency character varying(20) DEFAULT 'weekly'::character varying,
    modality character varying(20) DEFAULT 'both'::character varying,
    requires_deposit boolean DEFAULT false,
    deposit_percent integer DEFAULT 50,
    deposit_fixed numeric(10,2),
    price_per_session numeric(10,2),
    cancellation_hours integer DEFAULT 24,
    voice_keywords text[],
    prep_instructions text,
    color character varying(20) DEFAULT '#696cff'::character varying,
    icon character varying(60) DEFAULT 'bx-calendar-check'::character varying,
    sort_order integer DEFAULT 0,
    is_active boolean DEFAULT true,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT consultorio_session_types_deposit_percent_check CHECK (((deposit_percent >= 0) AND (deposit_percent <= 100)))
);


--
-- Name: conversations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.conversations (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    agent_id uuid NOT NULL,
    contact_phone character varying(30),
    contact_name character varying(120),
    contact_email character varying(254),
    channel character varying(30) DEFAULT 'voice'::character varying NOT NULL,
    status character varying(30) DEFAULT 'active'::character varying NOT NULL,
    started_at timestamp with time zone DEFAULT now() NOT NULL,
    ended_at timestamp with time zone,
    duration_secs integer,
    outcome character varying(50),
    outcome_data jsonb,
    recording_url text,
    summary text,
    sentiment character varying(20),
    metadata jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    assigned_to uuid,
    analysis jsonb,
    analyzed_at timestamp with time zone,
    needs_human boolean DEFAULT false NOT NULL,
    handoff_reason text,
    handoff_at timestamp with time zone,
    handoff_resolved_at timestamp with time zone,
    lead_id uuid
);


--
-- Name: customer_payments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.customer_payments (
    id bigint NOT NULL,
    tenant_id uuid NOT NULL,
    appointment_id uuid,
    concept text DEFAULT 'Anticipo de cita'::text NOT NULL,
    amount_cents integer NOT NULL,
    currency character varying(3) DEFAULT 'mxn'::character varying NOT NULL,
    status character varying(20) DEFAULT 'pending'::character varying NOT NULL,
    stripe_session character varying(255),
    stripe_payment_intent character varying(255),
    payment_url text,
    paid_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT customer_payments_status_check CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'paid'::character varying, 'expired'::character varying, 'refunded'::character varying])::text[])))
);


--
-- Name: customer_payments_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.customer_payments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: customer_payments_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.customer_payments_id_seq OWNED BY public.customer_payments.id;


--
-- Name: doctors; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.doctors (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    name character varying(200) NOT NULL,
    specialty character varying(100),
    phone character varying(30),
    email character varying(200),
    is_active boolean DEFAULT true NOT NULL,
    schedule_config jsonb DEFAULT '{}'::jsonb NOT NULL,
    color character varying(20) DEFAULT '#696cff'::character varying,
    avatar_initials character varying(4),
    sort_order integer DEFAULT 0,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    license_number character varying(50),
    avatar_url text,
    room character varying(80)
);


--
-- Name: invoices; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.invoices (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    subscription_id uuid,
    stripe_invoice_id character varying(100),
    stripe_payment_url text,
    amount_cents integer DEFAULT 0 NOT NULL,
    currency character varying(3) DEFAULT 'mxn'::character varying NOT NULL,
    status character varying(30) DEFAULT 'draft'::character varying NOT NULL,
    period_start timestamp with time zone,
    period_end timestamp with time zone,
    paid_at timestamp with time zone,
    due_date timestamp with time zone,
    pdf_url text,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: kb_chunks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.kb_chunks (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    document_id uuid NOT NULL,
    agent_id uuid,
    content text NOT NULL,
    chunk_index integer DEFAULT 0 NOT NULL,
    token_count integer DEFAULT 0 NOT NULL,
    source_type character varying(20) DEFAULT 'text'::character varying NOT NULL,
    source_url text,
    page_number integer,
    heading text,
    embedding public.vector(1536),
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: kb_documents; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.kb_documents (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    agent_id uuid,
    title character varying(200) NOT NULL,
    content text,
    file_url text,
    file_type character varying(20),
    chunk_count integer DEFAULT 0,
    status character varying(20) DEFAULT 'pending'::character varying NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    source_url text,
    metadata jsonb DEFAULT '{}'::jsonb NOT NULL
);


--
-- Name: leads; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.leads (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    conversation_id uuid,
    name character varying(120),
    phone character varying(30),
    email character varying(254),
    status character varying(30) DEFAULT 'new'::character varying NOT NULL,
    score integer DEFAULT 0,
    notes text,
    source_channel character varying(30),
    source_agent_id uuid,
    custom_data jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    assigned_to uuid,
    visit_count integer DEFAULT 0 NOT NULL,
    no_show_count integer DEFAULT 0 NOT NULL,
    cancel_count integer DEFAULT 0 NOT NULL,
    last_visit_at timestamp with time zone,
    total_spent_cents bigint DEFAULT 0 NOT NULL
);


--
-- Name: messages; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.messages (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    conversation_id uuid NOT NULL,
    tenant_id uuid NOT NULL,
    role character varying(20) NOT NULL,
    content text NOT NULL,
    tool_name character varying(60),
    tool_input jsonb,
    tool_output jsonb,
    tokens_used integer,
    latency_ms integer,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: notifications; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.notifications (
    id bigint NOT NULL,
    tenant_id uuid NOT NULL,
    type character varying(60) NOT NULL,
    title text NOT NULL,
    body text,
    link text,
    is_read boolean DEFAULT false NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: notifications_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.notifications_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: notifications_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.notifications_id_seq OWNED BY public.notifications.id;


--
-- Name: order_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.order_items (
    id bigint NOT NULL,
    order_id uuid NOT NULL,
    product_id uuid,
    name character varying(160) NOT NULL,
    unit_cents integer NOT NULL,
    quantity integer NOT NULL,
    line_cents integer NOT NULL,
    CONSTRAINT order_items_quantity_check CHECK ((quantity > 0))
);


--
-- Name: order_items_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.order_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: order_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.order_items_id_seq OWNED BY public.order_items.id;


--
-- Name: orders; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.orders (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    conversation_id uuid,
    customer_name character varying(160),
    customer_phone character varying(30),
    channel character varying(20) DEFAULT 'whatsapp'::character varying NOT NULL,
    status character varying(20) DEFAULT 'pending'::character varying NOT NULL,
    total_cents integer DEFAULT 0 NOT NULL,
    currency character varying(3) DEFAULT 'mxn'::character varying NOT NULL,
    stripe_session character varying(255),
    payment_url text,
    paid_at timestamp with time zone,
    notes text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    lead_id uuid,
    CONSTRAINT orders_status_check CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'paid'::character varying, 'fulfilled'::character varying, 'cancelled'::character varying])::text[])))
);


--
-- Name: plans; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.plans (
    key character varying(40) NOT NULL,
    name character varying(80) NOT NULL,
    monthly_cents integer DEFAULT 0 NOT NULL,
    included_minutes integer DEFAULT 0 NOT NULL,
    max_agents integer DEFAULT 1 NOT NULL,
    overage_per_min_cents integer DEFAULT 0 NOT NULL,
    features jsonb DEFAULT '[]'::jsonb NOT NULL,
    stripe_price_env character varying(80),
    is_active boolean DEFAULT true NOT NULL,
    sort_order integer DEFAULT 0 NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: platform_config; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.platform_config (
    key character varying(60) NOT NULL,
    value jsonb NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: products; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.products (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    name character varying(160) NOT NULL,
    description text,
    price_cents integer NOT NULL,
    currency character varying(3) DEFAULT 'mxn'::character varying NOT NULL,
    image_url text,
    category character varying(80),
    sku character varying(80),
    stock integer,
    is_active boolean DEFAULT true NOT NULL,
    sort_order integer DEFAULT 0 NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    attributes jsonb DEFAULT '{}'::jsonb NOT NULL,
    images jsonb DEFAULT '[]'::jsonb NOT NULL,
    CONSTRAINT products_price_cents_check CHECK ((price_cents >= 0))
);


--
-- Name: professionals; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.professionals (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    name character varying(200) NOT NULL,
    area character varying(100),
    specialty character varying(200),
    phone character varying(30),
    email character varying(200),
    bio text,
    license_number character varying(50),
    avatar_initials character varying(4),
    color character varying(20) DEFAULT '#696cff'::character varying,
    is_active boolean DEFAULT true NOT NULL,
    schedule_config jsonb DEFAULT '{}'::jsonb NOT NULL,
    video_link text,
    modality character varying(20) DEFAULT 'both'::character varying,
    sort_order integer DEFAULT 0,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    specialty_type character varying(30) DEFAULT 'other'::character varying,
    avatar_url text
);


--
-- Name: provider_rates; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.provider_rates (
    provider character varying(40) NOT NULL,
    label character varying(80) NOT NULL,
    unit character varying(20) NOT NULL,
    rate_cents numeric(10,4) NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: qualification_questions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.qualification_questions (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    professional_id uuid,
    session_type_id uuid,
    question text NOT NULL,
    hint text,
    answer_type character varying(20) DEFAULT 'yesno'::character varying,
    importance smallint DEFAULT 5 NOT NULL,
    disqualify_on character varying(10),
    sort_order integer DEFAULT 0,
    is_active boolean DEFAULT true,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT qualification_questions_importance_check CHECK (((importance >= 1) AND (importance <= 10)))
);


--
-- Name: schema_migrations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.schema_migrations (
    filename text NOT NULL,
    checksum text NOT NULL,
    applied_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: service_types; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.service_types (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    name character varying(100) NOT NULL,
    slug character varying(60) NOT NULL,
    duration_mins integer DEFAULT 30 NOT NULL,
    is_urgency boolean DEFAULT false NOT NULL,
    requires_deposit boolean DEFAULT false NOT NULL,
    deposit_amount numeric(10,2) DEFAULT 0,
    prep_instructions text,
    post_instructions text,
    voice_keywords text[],
    default_doctor_id uuid,
    color character varying(20) DEFAULT '#696cff'::character varying,
    icon character varying(60) DEFAULT 'bx-tooth'::character varying,
    sort_order integer DEFAULT 0,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    doctor_ids uuid[] DEFAULT '{}'::uuid[]
);


--
-- Name: session_reminders; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.session_reminders (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    session_id uuid NOT NULL,
    tenant_id uuid NOT NULL,
    reminder_type character varying(30) NOT NULL,
    channel character varying(20) DEFAULT 'whatsapp'::character varying,
    message_sid character varying(100),
    status character varying(20) DEFAULT 'sent'::character varying,
    sent_at timestamp with time zone DEFAULT now()
);


--
-- Name: session_series; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.session_series (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    professional_id uuid,
    session_type_id uuid,
    conversation_id uuid,
    lead_id uuid,
    patient_name character varying(200),
    patient_phone character varying(30),
    patient_email character varying(200),
    total_sessions integer DEFAULT 8 NOT NULL,
    sessions_confirmed integer DEFAULT 0,
    frequency character varying(20) DEFAULT 'weekly'::character varying,
    modality character varying(20) DEFAULT 'presencial'::character varying,
    status character varying(30) DEFAULT 'pending_professional'::character varying,
    notes text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: sessions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sessions (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    series_id uuid,
    professional_id uuid,
    session_type_id uuid,
    conversation_id uuid,
    lead_id uuid,
    patient_name character varying(200),
    patient_phone character varying(30),
    patient_email character varying(200),
    session_number integer DEFAULT 1,
    scheduled_at timestamp with time zone NOT NULL,
    duration_mins integer DEFAULT 60 NOT NULL,
    status character varying(30) DEFAULT 'pending_professional'::character varying NOT NULL,
    modality character varying(20) DEFAULT 'presencial'::character varying,
    video_link text,
    deposit_status character varying(20) DEFAULT 'none'::character varying,
    deposit_amount numeric(10,2),
    deposit_payment_link text,
    deposit_payment_intent character varying(200),
    professional_confirmed_at timestamp with time zone,
    patient_confirmed_at timestamp with time zone,
    cancellation_requested_at timestamp with time zone,
    reminder_24h_sent boolean DEFAULT false,
    reminder_2h_sent boolean DEFAULT false,
    post_session_sent boolean DEFAULT false,
    notes text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: simulator_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.simulator_logs (
    id bigint NOT NULL,
    tenant_id uuid NOT NULL,
    user_id uuid NOT NULL,
    session_id text NOT NULL,
    scenario text,
    role character varying(10) NOT NULL,
    content text NOT NULL,
    tokens_used integer,
    latency_ms integer,
    model text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT simulator_logs_role_check CHECK (((role)::text = ANY ((ARRAY['user'::character varying, 'assistant'::character varying])::text[])))
);


--
-- Name: simulator_logs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.simulator_logs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: simulator_logs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.simulator_logs_id_seq OWNED BY public.simulator_logs.id;


--
-- Name: simulator_security_events; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.simulator_security_events (
    id bigint NOT NULL,
    tenant_id uuid NOT NULL,
    user_id uuid NOT NULL,
    session_id text NOT NULL,
    message text NOT NULL,
    patterns text[] DEFAULT '{}'::text[] NOT NULL,
    severity character varying(10) DEFAULT 'medium'::character varying NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT simulator_security_events_severity_check CHECK (((severity)::text = ANY ((ARRAY['low'::character varying, 'medium'::character varying, 'high'::character varying])::text[])))
);


--
-- Name: simulator_security_events_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.simulator_security_events_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: simulator_security_events_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.simulator_security_events_id_seq OWNED BY public.simulator_security_events.id;


--
-- Name: subscriptions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.subscriptions (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    stripe_customer_id character varying(100),
    stripe_subscription_id character varying(100),
    stripe_price_id character varying(100),
    stripe_meter_item_id character varying(100),
    plan character varying(30) DEFAULT 'starter'::character varying NOT NULL,
    status character varying(30) DEFAULT 'trialing'::character varying NOT NULL,
    current_period_start timestamp with time zone,
    current_period_end timestamp with time zone,
    trial_end timestamp with time zone,
    canceled_at timestamp with time zone,
    metadata jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: tenants; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tenants (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    slug character varying(60) NOT NULL,
    name character varying(120) NOT NULL,
    plan character varying(30) DEFAULT 'starter'::character varying NOT NULL,
    status character varying(20) DEFAULT 'active'::character varying NOT NULL,
    timezone character varying(60) DEFAULT 'America/Mexico_City'::character varying NOT NULL,
    locale character varying(10) DEFAULT 'es-MX'::character varying NOT NULL,
    max_agents integer DEFAULT 1 NOT NULL,
    max_minutes_mo integer DEFAULT 500 NOT NULL,
    minutes_used_mo integer DEFAULT 0 NOT NULL,
    settings jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    stripe_customer_id character varying(100),
    avatar_url text,
    is_ready boolean DEFAULT false NOT NULL,
    setup_steps jsonb DEFAULT '{}'::jsonb NOT NULL,
    widget_key character varying(40) DEFAULT ('wgt_'::text || replace((gen_random_uuid())::text, '-'::text, ''::text))
);


--
-- Name: twilio_numbers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.twilio_numbers (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    phone_number character varying(30) NOT NULL,
    friendly_name character varying(120),
    account_sid character varying(80),
    tenant_id uuid,
    status character varying(20) DEFAULT 'available'::character varying NOT NULL,
    capabilities jsonb DEFAULT '{"sms": false, "voice": true, "whatsapp": false}'::jsonb NOT NULL,
    monthly_cost_cents integer DEFAULT 0 NOT NULL,
    notes text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: urgency_shifts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.urgency_shifts (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    doctor_id uuid,
    day_of_week smallint NOT NULL,
    start_time time without time zone NOT NULL,
    end_time time without time zone NOT NULL,
    phone character varying(30) NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: usage_records; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.usage_records (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    subscription_id uuid,
    period_start timestamp with time zone NOT NULL,
    period_end timestamp with time zone NOT NULL,
    minutes_included integer DEFAULT 0 NOT NULL,
    minutes_used integer DEFAULT 0 NOT NULL,
    minutes_overage integer DEFAULT 0 NOT NULL,
    plan_amount_cents integer DEFAULT 0 NOT NULL,
    overage_amount_cents integer DEFAULT 0 NOT NULL,
    total_amount_cents integer DEFAULT 0 NOT NULL,
    reported_to_stripe boolean DEFAULT false NOT NULL,
    stripe_usage_record_id character varying(100),
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.users (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    email character varying(254) NOT NULL,
    password_hash text NOT NULL,
    name character varying(120) NOT NULL,
    role character varying(30) DEFAULT 'admin'::character varying NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    last_login_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    avatar_url text,
    terms_accepted_at timestamp with time zone
);


--
-- Name: waitlist; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.waitlist (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    lead_id uuid,
    service_type_id uuid,
    doctor_id uuid,
    preferred_from timestamp with time zone,
    preferred_to timestamp with time zone,
    note text,
    status character varying(20) DEFAULT 'waiting'::character varying NOT NULL,
    notified_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: audit_log id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.audit_log ALTER COLUMN id SET DEFAULT nextval('public.audit_log_id_seq'::regclass);


--
-- Name: customer_payments id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_payments ALTER COLUMN id SET DEFAULT nextval('public.customer_payments_id_seq'::regclass);


--
-- Name: notifications id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notifications ALTER COLUMN id SET DEFAULT nextval('public.notifications_id_seq'::regclass);


--
-- Name: order_items id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.order_items ALTER COLUMN id SET DEFAULT nextval('public.order_items_id_seq'::regclass);


--
-- Name: simulator_logs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.simulator_logs ALTER COLUMN id SET DEFAULT nextval('public.simulator_logs_id_seq'::regclass);


--
-- Name: simulator_security_events id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.simulator_security_events ALTER COLUMN id SET DEFAULT nextval('public.simulator_security_events_id_seq'::regclass);


--
-- Name: agents agents_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agents
    ADD CONSTRAINT agents_pkey PRIMARY KEY (id);


--
-- Name: appointment_reminders appointment_reminders_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.appointment_reminders
    ADD CONSTRAINT appointment_reminders_pkey PRIMARY KEY (id);


--
-- Name: appointments appointments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.appointments
    ADD CONSTRAINT appointments_pkey PRIMARY KEY (id);


--
-- Name: audit_log audit_log_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.audit_log
    ADD CONSTRAINT audit_log_pkey PRIMARY KEY (id);


--
-- Name: campaign_contacts campaign_contacts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.campaign_contacts
    ADD CONSTRAINT campaign_contacts_pkey PRIMARY KEY (id);


--
-- Name: campaigns campaigns_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.campaigns
    ADD CONSTRAINT campaigns_pkey PRIMARY KEY (id);


--
-- Name: consultorio_session_types consultorio_session_types_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.consultorio_session_types
    ADD CONSTRAINT consultorio_session_types_pkey PRIMARY KEY (id);


--
-- Name: consultorio_session_types consultorio_session_types_tenant_id_slug_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.consultorio_session_types
    ADD CONSTRAINT consultorio_session_types_tenant_id_slug_key UNIQUE (tenant_id, slug);


--
-- Name: conversations conversations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.conversations
    ADD CONSTRAINT conversations_pkey PRIMARY KEY (id);


--
-- Name: customer_payments customer_payments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_payments
    ADD CONSTRAINT customer_payments_pkey PRIMARY KEY (id);


--
-- Name: doctors doctors_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.doctors
    ADD CONSTRAINT doctors_pkey PRIMARY KEY (id);


--
-- Name: invoices invoices_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoices
    ADD CONSTRAINT invoices_pkey PRIMARY KEY (id);


--
-- Name: invoices invoices_stripe_invoice_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoices
    ADD CONSTRAINT invoices_stripe_invoice_id_key UNIQUE (stripe_invoice_id);


--
-- Name: kb_chunks kb_chunks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.kb_chunks
    ADD CONSTRAINT kb_chunks_pkey PRIMARY KEY (id);


--
-- Name: kb_documents kb_documents_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.kb_documents
    ADD CONSTRAINT kb_documents_pkey PRIMARY KEY (id);


--
-- Name: leads leads_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.leads
    ADD CONSTRAINT leads_pkey PRIMARY KEY (id);


--
-- Name: messages messages_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.messages
    ADD CONSTRAINT messages_pkey PRIMARY KEY (id);


--
-- Name: notifications notifications_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notifications
    ADD CONSTRAINT notifications_pkey PRIMARY KEY (id);


--
-- Name: order_items order_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.order_items
    ADD CONSTRAINT order_items_pkey PRIMARY KEY (id);


--
-- Name: orders orders_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.orders
    ADD CONSTRAINT orders_pkey PRIMARY KEY (id);


--
-- Name: plans plans_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.plans
    ADD CONSTRAINT plans_pkey PRIMARY KEY (key);


--
-- Name: platform_config platform_config_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.platform_config
    ADD CONSTRAINT platform_config_pkey PRIMARY KEY (key);


--
-- Name: products products_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.products
    ADD CONSTRAINT products_pkey PRIMARY KEY (id);


--
-- Name: professionals professionals_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.professionals
    ADD CONSTRAINT professionals_pkey PRIMARY KEY (id);


--
-- Name: provider_rates provider_rates_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.provider_rates
    ADD CONSTRAINT provider_rates_pkey PRIMARY KEY (provider);


--
-- Name: qualification_questions qualification_questions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.qualification_questions
    ADD CONSTRAINT qualification_questions_pkey PRIMARY KEY (id);


--
-- Name: schema_migrations schema_migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.schema_migrations
    ADD CONSTRAINT schema_migrations_pkey PRIMARY KEY (filename);


--
-- Name: service_types service_types_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.service_types
    ADD CONSTRAINT service_types_pkey PRIMARY KEY (id);


--
-- Name: service_types service_types_tenant_id_slug_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.service_types
    ADD CONSTRAINT service_types_tenant_id_slug_key UNIQUE (tenant_id, slug);


--
-- Name: session_reminders session_reminders_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.session_reminders
    ADD CONSTRAINT session_reminders_pkey PRIMARY KEY (id);


--
-- Name: session_series session_series_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.session_series
    ADD CONSTRAINT session_series_pkey PRIMARY KEY (id);


--
-- Name: sessions sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT sessions_pkey PRIMARY KEY (id);


--
-- Name: simulator_logs simulator_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.simulator_logs
    ADD CONSTRAINT simulator_logs_pkey PRIMARY KEY (id);


--
-- Name: simulator_security_events simulator_security_events_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.simulator_security_events
    ADD CONSTRAINT simulator_security_events_pkey PRIMARY KEY (id);


--
-- Name: subscriptions subscriptions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscriptions
    ADD CONSTRAINT subscriptions_pkey PRIMARY KEY (id);


--
-- Name: subscriptions subscriptions_stripe_customer_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscriptions
    ADD CONSTRAINT subscriptions_stripe_customer_id_key UNIQUE (stripe_customer_id);


--
-- Name: subscriptions subscriptions_stripe_subscription_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscriptions
    ADD CONSTRAINT subscriptions_stripe_subscription_id_key UNIQUE (stripe_subscription_id);


--
-- Name: tenants tenants_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenants
    ADD CONSTRAINT tenants_pkey PRIMARY KEY (id);


--
-- Name: tenants tenants_slug_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenants
    ADD CONSTRAINT tenants_slug_key UNIQUE (slug);


--
-- Name: tenants tenants_widget_key_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenants
    ADD CONSTRAINT tenants_widget_key_key UNIQUE (widget_key);


--
-- Name: twilio_numbers twilio_numbers_phone_number_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.twilio_numbers
    ADD CONSTRAINT twilio_numbers_phone_number_key UNIQUE (phone_number);


--
-- Name: twilio_numbers twilio_numbers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.twilio_numbers
    ADD CONSTRAINT twilio_numbers_pkey PRIMARY KEY (id);


--
-- Name: urgency_shifts urgency_shifts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.urgency_shifts
    ADD CONSTRAINT urgency_shifts_pkey PRIMARY KEY (id);


--
-- Name: usage_records usage_records_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usage_records
    ADD CONSTRAINT usage_records_pkey PRIMARY KEY (id);


--
-- Name: users users_email_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_email_key UNIQUE (email);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: waitlist waitlist_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.waitlist
    ADD CONSTRAINT waitlist_pkey PRIMARY KEY (id);


--
-- Name: idx_agents_tenant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_agents_tenant ON public.agents USING btree (tenant_id);


--
-- Name: idx_appointments_date; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_appointments_date ON public.appointments USING btree (tenant_id, scheduled_at);


--
-- Name: idx_appointments_tenant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_appointments_tenant ON public.appointments USING btree (tenant_id);


--
-- Name: idx_appt_checkout_session; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_appt_checkout_session ON public.appointments USING btree (deposit_checkout_session) WHERE (deposit_checkout_session IS NOT NULL);


--
-- Name: idx_appt_confirm_lookup; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_appt_confirm_lookup ON public.appointments USING btree (tenant_id, patient_phone, scheduled_at) WHERE ((confirmation_status)::text = 'pending'::text);


--
-- Name: idx_appt_doctor; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_appt_doctor ON public.appointments USING btree (doctor_id);


--
-- Name: idx_appt_reminder; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_appt_reminder ON public.appointments USING btree (tenant_id, scheduled_at, reminder_24h_sent) WHERE ((status)::text <> 'cancelled'::text);


--
-- Name: idx_audit_log_action; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_audit_log_action ON public.audit_log USING btree (action);


--
-- Name: idx_audit_log_created; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_audit_log_created ON public.audit_log USING btree (created_at DESC);


--
-- Name: idx_audit_tenant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_audit_tenant ON public.audit_log USING btree (tenant_id, created_at DESC);


--
-- Name: idx_campaign_contacts_camp; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_campaign_contacts_camp ON public.campaign_contacts USING btree (campaign_id, status);


--
-- Name: idx_campaign_contacts_lock; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_campaign_contacts_lock ON public.campaign_contacts USING btree (locked_until) WHERE (locked_until IS NOT NULL);


--
-- Name: idx_campaign_contacts_next; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_campaign_contacts_next ON public.campaign_contacts USING btree (next_attempt_at, status) WHERE ((status)::text = 'pending'::text);


--
-- Name: idx_campaigns_tenant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_campaigns_tenant ON public.campaigns USING btree (tenant_id, status);


--
-- Name: idx_conv_analyzed; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_conv_analyzed ON public.conversations USING btree (tenant_id, analyzed_at DESC) WHERE (analyzed_at IS NOT NULL);


--
-- Name: idx_conv_kb_gap; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_conv_kb_gap ON public.conversations USING btree (tenant_id, created_at DESC) WHERE ((analysis ->> 'kb_gap'::text) = 'true'::text);


--
-- Name: idx_conv_needs_human; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_conv_needs_human ON public.conversations USING btree (tenant_id, handoff_at DESC) WHERE ((needs_human = true) AND (handoff_resolved_at IS NULL));


--
-- Name: idx_conversations_agent; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_conversations_agent ON public.conversations USING btree (agent_id);


--
-- Name: idx_conversations_lead; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_conversations_lead ON public.conversations USING btree (lead_id);


--
-- Name: idx_conversations_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_conversations_status ON public.conversations USING btree (tenant_id, status);


--
-- Name: idx_conversations_tenant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_conversations_tenant ON public.conversations USING btree (tenant_id);


--
-- Name: idx_convs_assigned; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_convs_assigned ON public.conversations USING btree (assigned_to);


--
-- Name: idx_csession_types_tenant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_csession_types_tenant ON public.consultorio_session_types USING btree (tenant_id);


--
-- Name: idx_custpay_session; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_custpay_session ON public.customer_payments USING btree (stripe_session);


--
-- Name: idx_custpay_tenant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_custpay_tenant ON public.customer_payments USING btree (tenant_id, created_at DESC);


--
-- Name: idx_doctors_tenant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_doctors_tenant ON public.doctors USING btree (tenant_id);


--
-- Name: idx_invoices_tenant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_invoices_tenant ON public.invoices USING btree (tenant_id, created_at DESC);


--
-- Name: idx_kb_chunks_document; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_kb_chunks_document ON public.kb_chunks USING btree (document_id);


--
-- Name: idx_kb_chunks_embedding; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_kb_chunks_embedding ON public.kb_chunks USING hnsw (embedding public.vector_cosine_ops) WITH (m='16', ef_construction='64');


--
-- Name: idx_kb_chunks_tenant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_kb_chunks_tenant ON public.kb_chunks USING btree (tenant_id);


--
-- Name: idx_leads_assigned; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_leads_assigned ON public.leads USING btree (assigned_to);


--
-- Name: idx_leads_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_leads_status ON public.leads USING btree (tenant_id, status);


--
-- Name: idx_leads_tenant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_leads_tenant ON public.leads USING btree (tenant_id);


--
-- Name: idx_messages_conv; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_messages_conv ON public.messages USING btree (conversation_id);


--
-- Name: idx_messages_tenant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_messages_tenant ON public.messages USING btree (tenant_id);


--
-- Name: idx_notif_tenant_new; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_notif_tenant_new ON public.notifications USING btree (tenant_id, id DESC) WHERE (is_read = false);


--
-- Name: idx_notif_tenant_since; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_notif_tenant_since ON public.notifications USING btree (tenant_id, id);


--
-- Name: idx_order_items_order; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_order_items_order ON public.order_items USING btree (order_id);


--
-- Name: idx_orders_lead; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_orders_lead ON public.orders USING btree (lead_id);


--
-- Name: idx_orders_session; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_orders_session ON public.orders USING btree (stripe_session);


--
-- Name: idx_orders_tenant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_orders_tenant ON public.orders USING btree (tenant_id, created_at DESC);


--
-- Name: idx_products_category; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_products_category ON public.products USING btree (tenant_id, category) WHERE (is_active = true);


--
-- Name: idx_products_tenant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_products_tenant ON public.products USING btree (tenant_id, is_active, sort_order);


--
-- Name: idx_professionals_specialty; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_professionals_specialty ON public.professionals USING btree (specialty_type);


--
-- Name: idx_professionals_tenant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_professionals_tenant ON public.professionals USING btree (tenant_id);


--
-- Name: idx_qual_questions_tenant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_qual_questions_tenant ON public.qualification_questions USING btree (tenant_id);


--
-- Name: idx_reminders_appt; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_reminders_appt ON public.appointment_reminders USING btree (appointment_id);


--
-- Name: idx_series_professional; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_series_professional ON public.session_series USING btree (professional_id);


--
-- Name: idx_series_tenant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_series_tenant ON public.session_series USING btree (tenant_id);


--
-- Name: idx_service_types_tenant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_service_types_tenant ON public.service_types USING btree (tenant_id);


--
-- Name: idx_session_reminders_session; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_session_reminders_session ON public.session_reminders USING btree (session_id);


--
-- Name: idx_sessions_phone; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_sessions_phone ON public.sessions USING btree (patient_phone);


--
-- Name: idx_sessions_professional; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_sessions_professional ON public.sessions USING btree (professional_id);


--
-- Name: idx_sessions_scheduled; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_sessions_scheduled ON public.sessions USING btree (scheduled_at);


--
-- Name: idx_sessions_series; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_sessions_series ON public.sessions USING btree (series_id);


--
-- Name: idx_sessions_tenant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_sessions_tenant ON public.sessions USING btree (tenant_id);


--
-- Name: idx_simevents_tenant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_simevents_tenant ON public.simulator_security_events USING btree (tenant_id, created_at DESC);


--
-- Name: idx_simlogs_tenant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_simlogs_tenant ON public.simulator_logs USING btree (tenant_id, session_id);


--
-- Name: idx_subscriptions_stripe; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_subscriptions_stripe ON public.subscriptions USING btree (stripe_subscription_id);


--
-- Name: idx_subscriptions_tenant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_subscriptions_tenant ON public.subscriptions USING btree (tenant_id);


--
-- Name: idx_tenants_widget_key; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_tenants_widget_key ON public.tenants USING btree (widget_key) WHERE (widget_key IS NOT NULL);


--
-- Name: idx_twilio_numbers_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_twilio_numbers_status ON public.twilio_numbers USING btree (status);


--
-- Name: idx_twilio_numbers_tenant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_twilio_numbers_tenant ON public.twilio_numbers USING btree (tenant_id);


--
-- Name: idx_urgency_shifts_tenant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_urgency_shifts_tenant ON public.urgency_shifts USING btree (tenant_id);


--
-- Name: idx_usage_tenant_period; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_usage_tenant_period ON public.usage_records USING btree (tenant_id, period_start DESC);


--
-- Name: idx_users_tenant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_users_tenant ON public.users USING btree (tenant_id);


--
-- Name: idx_waitlist_lead; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_waitlist_lead ON public.waitlist USING btree (lead_id);


--
-- Name: idx_waitlist_tenant_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_waitlist_tenant_status ON public.waitlist USING btree (tenant_id, status);


--
-- Name: uniq_leads_tenant_phone; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_leads_tenant_phone ON public.leads USING btree (tenant_id, phone) WHERE ((phone IS NOT NULL) AND ((phone)::text <> ''::text));


--
-- Name: agents trg_agents_updated; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_agents_updated BEFORE UPDATE ON public.agents FOR EACH ROW EXECUTE FUNCTION public.update_updated_at();


--
-- Name: appointments trg_appointments_updated; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_appointments_updated BEFORE UPDATE ON public.appointments FOR EACH ROW EXECUTE FUNCTION public.update_updated_at();


--
-- Name: campaign_contacts trg_campaign_contacts_updated; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_campaign_contacts_updated BEFORE UPDATE ON public.campaign_contacts FOR EACH ROW EXECUTE FUNCTION public.update_updated_at();


--
-- Name: campaigns trg_campaigns_updated; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_campaigns_updated BEFORE UPDATE ON public.campaigns FOR EACH ROW EXECUTE FUNCTION public.update_updated_at();


--
-- Name: consultorio_session_types trg_csession_types_updated; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_csession_types_updated BEFORE UPDATE ON public.consultorio_session_types FOR EACH ROW EXECUTE FUNCTION public.update_updated_at();


--
-- Name: doctors trg_doctors_updated; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_doctors_updated BEFORE UPDATE ON public.doctors FOR EACH ROW EXECUTE FUNCTION public.update_updated_at();


--
-- Name: kb_documents trg_kb_updated; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_kb_updated BEFORE UPDATE ON public.kb_documents FOR EACH ROW EXECUTE FUNCTION public.update_updated_at();


--
-- Name: leads trg_leads_updated; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_leads_updated BEFORE UPDATE ON public.leads FOR EACH ROW EXECUTE FUNCTION public.update_updated_at();


--
-- Name: professionals trg_professionals_updated; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_professionals_updated BEFORE UPDATE ON public.professionals FOR EACH ROW EXECUTE FUNCTION public.update_updated_at();


--
-- Name: session_series trg_series_updated; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_series_updated BEFORE UPDATE ON public.session_series FOR EACH ROW EXECUTE FUNCTION public.update_updated_at();


--
-- Name: sessions trg_sessions_updated; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_sessions_updated BEFORE UPDATE ON public.sessions FOR EACH ROW EXECUTE FUNCTION public.update_updated_at();


--
-- Name: subscriptions trg_subscriptions_updated; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_subscriptions_updated BEFORE UPDATE ON public.subscriptions FOR EACH ROW EXECUTE FUNCTION public.update_updated_at();


--
-- Name: tenants trg_tenants_updated; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_tenants_updated BEFORE UPDATE ON public.tenants FOR EACH ROW EXECUTE FUNCTION public.update_updated_at();


--
-- Name: users trg_users_updated; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_users_updated BEFORE UPDATE ON public.users FOR EACH ROW EXECUTE FUNCTION public.update_updated_at();


--
-- Name: agents agents_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agents
    ADD CONSTRAINT agents_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: appointment_reminders appointment_reminders_appointment_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.appointment_reminders
    ADD CONSTRAINT appointment_reminders_appointment_id_fkey FOREIGN KEY (appointment_id) REFERENCES public.appointments(id) ON DELETE CASCADE;


--
-- Name: appointment_reminders appointment_reminders_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.appointment_reminders
    ADD CONSTRAINT appointment_reminders_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: appointments appointments_conversation_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.appointments
    ADD CONSTRAINT appointments_conversation_id_fkey FOREIGN KEY (conversation_id) REFERENCES public.conversations(id);


--
-- Name: appointments appointments_doctor_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.appointments
    ADD CONSTRAINT appointments_doctor_id_fkey FOREIGN KEY (doctor_id) REFERENCES public.doctors(id) ON DELETE SET NULL;


--
-- Name: appointments appointments_lead_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.appointments
    ADD CONSTRAINT appointments_lead_id_fkey FOREIGN KEY (lead_id) REFERENCES public.leads(id);


--
-- Name: appointments appointments_service_type_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.appointments
    ADD CONSTRAINT appointments_service_type_id_fkey FOREIGN KEY (service_type_id) REFERENCES public.service_types(id) ON DELETE SET NULL;


--
-- Name: appointments appointments_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.appointments
    ADD CONSTRAINT appointments_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: audit_log audit_log_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.audit_log
    ADD CONSTRAINT audit_log_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: audit_log audit_log_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.audit_log
    ADD CONSTRAINT audit_log_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id);


--
-- Name: campaign_contacts campaign_contacts_campaign_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.campaign_contacts
    ADD CONSTRAINT campaign_contacts_campaign_id_fkey FOREIGN KEY (campaign_id) REFERENCES public.campaigns(id) ON DELETE CASCADE;


--
-- Name: campaign_contacts campaign_contacts_conversation_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.campaign_contacts
    ADD CONSTRAINT campaign_contacts_conversation_id_fkey FOREIGN KEY (conversation_id) REFERENCES public.conversations(id);


--
-- Name: campaign_contacts campaign_contacts_lead_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.campaign_contacts
    ADD CONSTRAINT campaign_contacts_lead_id_fkey FOREIGN KEY (lead_id) REFERENCES public.leads(id);


--
-- Name: campaign_contacts campaign_contacts_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.campaign_contacts
    ADD CONSTRAINT campaign_contacts_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: campaigns campaigns_agent_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.campaigns
    ADD CONSTRAINT campaigns_agent_id_fkey FOREIGN KEY (agent_id) REFERENCES public.agents(id);


--
-- Name: campaigns campaigns_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.campaigns
    ADD CONSTRAINT campaigns_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: consultorio_session_types consultorio_session_types_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.consultorio_session_types
    ADD CONSTRAINT consultorio_session_types_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: conversations conversations_agent_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.conversations
    ADD CONSTRAINT conversations_agent_id_fkey FOREIGN KEY (agent_id) REFERENCES public.agents(id);


--
-- Name: conversations conversations_assigned_to_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.conversations
    ADD CONSTRAINT conversations_assigned_to_fkey FOREIGN KEY (assigned_to) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: conversations conversations_lead_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.conversations
    ADD CONSTRAINT conversations_lead_id_fkey FOREIGN KEY (lead_id) REFERENCES public.leads(id) ON DELETE SET NULL;


--
-- Name: conversations conversations_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.conversations
    ADD CONSTRAINT conversations_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: customer_payments customer_payments_appointment_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_payments
    ADD CONSTRAINT customer_payments_appointment_id_fkey FOREIGN KEY (appointment_id) REFERENCES public.appointments(id) ON DELETE SET NULL;


--
-- Name: customer_payments customer_payments_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_payments
    ADD CONSTRAINT customer_payments_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: doctors doctors_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.doctors
    ADD CONSTRAINT doctors_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: invoices invoices_subscription_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoices
    ADD CONSTRAINT invoices_subscription_id_fkey FOREIGN KEY (subscription_id) REFERENCES public.subscriptions(id);


--
-- Name: invoices invoices_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoices
    ADD CONSTRAINT invoices_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: kb_chunks kb_chunks_agent_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.kb_chunks
    ADD CONSTRAINT kb_chunks_agent_id_fkey FOREIGN KEY (agent_id) REFERENCES public.agents(id);


--
-- Name: kb_chunks kb_chunks_document_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.kb_chunks
    ADD CONSTRAINT kb_chunks_document_id_fkey FOREIGN KEY (document_id) REFERENCES public.kb_documents(id) ON DELETE CASCADE;


--
-- Name: kb_chunks kb_chunks_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.kb_chunks
    ADD CONSTRAINT kb_chunks_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: kb_documents kb_documents_agent_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.kb_documents
    ADD CONSTRAINT kb_documents_agent_id_fkey FOREIGN KEY (agent_id) REFERENCES public.agents(id);


--
-- Name: kb_documents kb_documents_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.kb_documents
    ADD CONSTRAINT kb_documents_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: leads leads_assigned_to_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.leads
    ADD CONSTRAINT leads_assigned_to_fkey FOREIGN KEY (assigned_to) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: leads leads_conversation_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.leads
    ADD CONSTRAINT leads_conversation_id_fkey FOREIGN KEY (conversation_id) REFERENCES public.conversations(id);


--
-- Name: leads leads_source_agent_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.leads
    ADD CONSTRAINT leads_source_agent_id_fkey FOREIGN KEY (source_agent_id) REFERENCES public.agents(id);


--
-- Name: leads leads_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.leads
    ADD CONSTRAINT leads_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: messages messages_conversation_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.messages
    ADD CONSTRAINT messages_conversation_id_fkey FOREIGN KEY (conversation_id) REFERENCES public.conversations(id) ON DELETE CASCADE;


--
-- Name: messages messages_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.messages
    ADD CONSTRAINT messages_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: notifications notifications_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notifications
    ADD CONSTRAINT notifications_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: order_items order_items_order_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.order_items
    ADD CONSTRAINT order_items_order_id_fkey FOREIGN KEY (order_id) REFERENCES public.orders(id) ON DELETE CASCADE;


--
-- Name: order_items order_items_product_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.order_items
    ADD CONSTRAINT order_items_product_id_fkey FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE SET NULL;


--
-- Name: orders orders_conversation_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.orders
    ADD CONSTRAINT orders_conversation_id_fkey FOREIGN KEY (conversation_id) REFERENCES public.conversations(id) ON DELETE SET NULL;


--
-- Name: orders orders_lead_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.orders
    ADD CONSTRAINT orders_lead_id_fkey FOREIGN KEY (lead_id) REFERENCES public.leads(id) ON DELETE SET NULL;


--
-- Name: orders orders_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.orders
    ADD CONSTRAINT orders_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: products products_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.products
    ADD CONSTRAINT products_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: professionals professionals_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.professionals
    ADD CONSTRAINT professionals_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: qualification_questions qualification_questions_professional_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.qualification_questions
    ADD CONSTRAINT qualification_questions_professional_id_fkey FOREIGN KEY (professional_id) REFERENCES public.professionals(id) ON DELETE CASCADE;


--
-- Name: qualification_questions qualification_questions_session_type_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.qualification_questions
    ADD CONSTRAINT qualification_questions_session_type_id_fkey FOREIGN KEY (session_type_id) REFERENCES public.consultorio_session_types(id) ON DELETE CASCADE;


--
-- Name: qualification_questions qualification_questions_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.qualification_questions
    ADD CONSTRAINT qualification_questions_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: service_types service_types_default_doctor_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.service_types
    ADD CONSTRAINT service_types_default_doctor_id_fkey FOREIGN KEY (default_doctor_id) REFERENCES public.doctors(id) ON DELETE SET NULL;


--
-- Name: service_types service_types_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.service_types
    ADD CONSTRAINT service_types_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: session_reminders session_reminders_session_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.session_reminders
    ADD CONSTRAINT session_reminders_session_id_fkey FOREIGN KEY (session_id) REFERENCES public.sessions(id) ON DELETE CASCADE;


--
-- Name: session_series session_series_conversation_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.session_series
    ADD CONSTRAINT session_series_conversation_id_fkey FOREIGN KEY (conversation_id) REFERENCES public.conversations(id) ON DELETE SET NULL;


--
-- Name: session_series session_series_lead_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.session_series
    ADD CONSTRAINT session_series_lead_id_fkey FOREIGN KEY (lead_id) REFERENCES public.leads(id) ON DELETE SET NULL;


--
-- Name: session_series session_series_professional_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.session_series
    ADD CONSTRAINT session_series_professional_id_fkey FOREIGN KEY (professional_id) REFERENCES public.professionals(id) ON DELETE SET NULL;


--
-- Name: session_series session_series_session_type_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.session_series
    ADD CONSTRAINT session_series_session_type_id_fkey FOREIGN KEY (session_type_id) REFERENCES public.consultorio_session_types(id) ON DELETE SET NULL;


--
-- Name: session_series session_series_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.session_series
    ADD CONSTRAINT session_series_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: sessions sessions_conversation_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT sessions_conversation_id_fkey FOREIGN KEY (conversation_id) REFERENCES public.conversations(id) ON DELETE SET NULL;


--
-- Name: sessions sessions_lead_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT sessions_lead_id_fkey FOREIGN KEY (lead_id) REFERENCES public.leads(id) ON DELETE SET NULL;


--
-- Name: sessions sessions_professional_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT sessions_professional_id_fkey FOREIGN KEY (professional_id) REFERENCES public.professionals(id) ON DELETE SET NULL;


--
-- Name: sessions sessions_series_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT sessions_series_id_fkey FOREIGN KEY (series_id) REFERENCES public.session_series(id) ON DELETE SET NULL;


--
-- Name: sessions sessions_session_type_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT sessions_session_type_id_fkey FOREIGN KEY (session_type_id) REFERENCES public.consultorio_session_types(id) ON DELETE SET NULL;


--
-- Name: sessions sessions_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT sessions_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: simulator_logs simulator_logs_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.simulator_logs
    ADD CONSTRAINT simulator_logs_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: simulator_security_events simulator_security_events_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.simulator_security_events
    ADD CONSTRAINT simulator_security_events_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: subscriptions subscriptions_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscriptions
    ADD CONSTRAINT subscriptions_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: twilio_numbers twilio_numbers_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.twilio_numbers
    ADD CONSTRAINT twilio_numbers_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE SET NULL;


--
-- Name: urgency_shifts urgency_shifts_doctor_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.urgency_shifts
    ADD CONSTRAINT urgency_shifts_doctor_id_fkey FOREIGN KEY (doctor_id) REFERENCES public.doctors(id) ON DELETE SET NULL;


--
-- Name: urgency_shifts urgency_shifts_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.urgency_shifts
    ADD CONSTRAINT urgency_shifts_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: usage_records usage_records_subscription_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usage_records
    ADD CONSTRAINT usage_records_subscription_id_fkey FOREIGN KEY (subscription_id) REFERENCES public.subscriptions(id);


--
-- Name: usage_records usage_records_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usage_records
    ADD CONSTRAINT usage_records_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: users users_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: waitlist waitlist_doctor_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.waitlist
    ADD CONSTRAINT waitlist_doctor_id_fkey FOREIGN KEY (doctor_id) REFERENCES public.doctors(id) ON DELETE SET NULL;


--
-- Name: waitlist waitlist_lead_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.waitlist
    ADD CONSTRAINT waitlist_lead_id_fkey FOREIGN KEY (lead_id) REFERENCES public.leads(id) ON DELETE SET NULL;


--
-- Name: waitlist waitlist_service_type_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.waitlist
    ADD CONSTRAINT waitlist_service_type_id_fkey FOREIGN KEY (service_type_id) REFERENCES public.service_types(id) ON DELETE SET NULL;


--
-- Name: waitlist waitlist_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.waitlist
    ADD CONSTRAINT waitlist_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: agents; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.agents ENABLE ROW LEVEL SECURITY;

--
-- Name: appointments; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.appointments ENABLE ROW LEVEL SECURITY;

--
-- Name: conversations; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.conversations ENABLE ROW LEVEL SECURITY;

--
-- Name: kb_documents; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.kb_documents ENABLE ROW LEVEL SECURITY;

--
-- Name: leads; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.leads ENABLE ROW LEVEL SECURITY;

--
-- Name: messages; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.messages ENABLE ROW LEVEL SECURITY;

--
-- Name: users; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.users ENABLE ROW LEVEL SECURITY;

--
-- PostgreSQL database dump complete
--

\unrestrict kfP73bQa6f3avcKlCA98krvdkvEGjjRPtrQ71MK2HUheyOBJrjReHZspQIBnpSD

