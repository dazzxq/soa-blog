-- =============================================================================
-- ProConnect — rich demo seed (English fake professionals + posts + graph)
-- Idempotent (INSERT IGNORE on explicit ids / unique keys) and NON-destructive.
-- Makes the feed / search / profiles look alive for the demo. Safe to re-run.
--
-- ID ranges (kept clear of the 1-5 demo users + auto-increment churn):
--   profile users   6..21        skills      300..360
--   feed posts      100..133     comments    200..230
--   connections via INSERT IGNORE (undirected unique pair_lo/pair_hi GENERATED)
--
-- password_hash reuses the existing demo bcrypt (login works: demo@123**).
-- avatars: i.pravatar.cc (deterministic by ?u=); covers: picsum.photos seed.
-- =============================================================================

-- ---------------------------------------------------------------------------
USE proconnect_profile;
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO users (id, username, email, password_hash, display_name, avatar_url, cover_url, headline, location, about, created_at, updated_at) VALUES
 (6,'sarahchen','sarah.chen@proconnect.demo','$2y$12$ALzJ3z471uDwTiudwJSMje8khpX0NQHKPTpoXRI9AhdIzFH6B5RaS','Sarah Chen','https://i.pravatar.cc/240?u=sarahchen','https://picsum.photos/seed/sarahchen/960/240','Senior Frontend Engineer @ Vercel · React & Design Systems','San Francisco, CA','Frontend engineer who loves turning complex UI problems into delightful, accessible experiences. Currently building the next generation of developer tools.', NOW() - INTERVAL 40 DAY, NOW()),
 (7,'marcusj','marcus.johnson@proconnect.demo','$2y$12$ALzJ3z471uDwTiudwJSMje8khpX0NQHKPTpoXRI9AhdIzFH6B5RaS','Marcus Johnson','https://i.pravatar.cc/240?u=marcusj','https://picsum.photos/seed/marcusj/960/240','Engineering Manager · Scaling teams & systems','Austin, TX','Helping engineers do the best work of their careers. Ex-startup founder. I write about leadership, distributed systems, and hiring.', NOW() - INTERVAL 38 DAY, NOW()),
 (8,'priyap','priya.patel@proconnect.demo','$2y$12$ALzJ3z471uDwTiudwJSMje8khpX0NQHKPTpoXRI9AhdIzFH6B5RaS','Priya Patel','https://i.pravatar.cc/240?u=priyap','https://picsum.photos/seed/priyap/960/240','Data Scientist · NLP & LLMs','London, UK','Turning messy data into decisions. Working on retrieval-augmented generation and evaluation pipelines. PhD in Computational Linguistics.', NOW() - INTERVAL 35 DAY, NOW()),
 (9,'davidkim','david.kim@proconnect.demo','$2y$12$ALzJ3z471uDwTiudwJSMje8khpX0NQHKPTpoXRI9AhdIzFH6B5RaS','David Kim','https://i.pravatar.cc/240?u=davidkim','https://picsum.photos/seed/davidkim/960/240','Site Reliability Engineer · Kubernetes & Observability','Seoul, South Korea','Keeping production calm at 3am so you don''t have to. I care about SLOs, blameless postmortems, and good dashboards.', NOW() - INTERVAL 33 DAY, NOW()),
 (10,'emmaw','emma.wilson@proconnect.demo','$2y$12$ALzJ3z471uDwTiudwJSMje8khpX0NQHKPTpoXRI9AhdIzFH6B5RaS','Emma Wilson','https://i.pravatar.cc/240?u=emmaw','https://picsum.photos/seed/emmaw/960/240','Product Designer · Design Systems & Accessibility','Berlin, Germany','Designing products people actually enjoy using. Strong opinions, loosely held. Accessibility is not optional.', NOW() - INTERVAL 30 DAY, NOW()),
 (11,'jamesr','james.rodriguez@proconnect.demo','$2y$12$ALzJ3z471uDwTiudwJSMje8khpX0NQHKPTpoXRI9AhdIzFH6B5RaS','James Rodriguez','https://i.pravatar.cc/240?u=jamesr','https://picsum.photos/seed/jamesr/960/240','Backend Engineer · Go & Distributed Systems','Madrid, Spain','I build fast, boring, reliable backends. Fan of Go, gRPC, and event-driven architectures.', NOW() - INTERVAL 28 DAY, NOW()),
 (12,'aishao','aisha.okafor@proconnect.demo','$2y$12$ALzJ3z471uDwTiudwJSMje8khpX0NQHKPTpoXRI9AhdIzFH6B5RaS','Aisha Okafor','https://i.pravatar.cc/240?u=aishao','https://picsum.photos/seed/aishao/960/240','Machine Learning Engineer · Computer Vision','Lagos, Nigeria','Shipping ML models to production. Computer vision for healthcare. Building Africa''s AI talent pipeline on the side.', NOW() - INTERVAL 26 DAY, NOW()),
 (13,'tomand','tom.anderson@proconnect.demo','$2y$12$ALzJ3z471uDwTiudwJSMje8khpX0NQHKPTpoXRI9AhdIzFH6B5RaS','Tom Anderson','https://i.pravatar.cc/240?u=tomand','https://picsum.photos/seed/tomand/960/240','Cloud Architect · AWS Community Builder','Seattle, WA','Designing cloud architectures that scale and don''t break the bank. 6x AWS certified. I love a good cost-optimization win.', NOW() - INTERVAL 24 DAY, NOW()),
 (14,'yukit','yuki.tanaka@proconnect.demo','$2y$12$ALzJ3z471uDwTiudwJSMje8khpX0NQHKPTpoXRI9AhdIzFH6B5RaS','Yuki Tanaka','https://i.pravatar.cc/240?u=yukit','https://picsum.photos/seed/yukit/960/240','iOS Engineer · Swift & SwiftUI','Tokyo, Japan','Crafting native mobile experiences. SwiftUI early adopter. Currently exploring on-device ML.', NOW() - INTERVAL 22 DAY, NOW()),
 (15,'oliviab','olivia.brown@proconnect.demo','$2y$12$ALzJ3z471uDwTiudwJSMje8khpX0NQHKPTpoXRI9AhdIzFH6B5RaS','Olivia Brown','https://i.pravatar.cc/240?u=oliviab','https://picsum.photos/seed/oliviab/960/240','Engineering Director · Platform & Infrastructure','Toronto, Canada','Building platforms and the teams behind them. Passionate about developer experience and inclusive engineering cultures.', NOW() - INTERVAL 20 DAY, NOW()),
 (16,'liamob','liam.obrien@proconnect.demo','$2y$12$ALzJ3z471uDwTiudwJSMje8khpX0NQHKPTpoXRI9AhdIzFH6B5RaS','Liam O''Brien','https://i.pravatar.cc/240?u=liamob','https://picsum.photos/seed/liamob/960/240','Security Engineer · AppSec & Threat Modeling','Dublin, Ireland','Breaking things so attackers can''t. AppSec, threat modeling, and secure-by-default systems. CTF player on weekends.', NOW() - INTERVAL 18 DAY, NOW()),
 (17,'sofiag','sofia.garcia@proconnect.demo','$2y$12$ALzJ3z471uDwTiudwJSMje8khpX0NQHKPTpoXRI9AhdIzFH6B5RaS','Sofia Garcia','https://i.pravatar.cc/240?u=sofiag','https://picsum.photos/seed/sofiag/960/240','Full-Stack Developer · TypeScript everywhere','Barcelona, Spain','Full-stack dev shipping end to end. TypeScript, Next.js, Postgres. Open-source contributor and conference speaker.', NOW() - INTERVAL 16 DAY, NOW()),
 (18,'rajs','raj.sharma@proconnect.demo','$2y$12$ALzJ3z471uDwTiudwJSMje8khpX0NQHKPTpoXRI9AhdIzFH6B5RaS','Raj Sharma','https://i.pravatar.cc/240?u=rajs','https://picsum.photos/seed/rajs/960/240','Platform Engineer · Kubernetes & Internal Developer Platforms','Bangalore, India','Building the golden path for hundreds of engineers. Kubernetes, Terraform, and a bit too much YAML.', NOW() - INTERVAL 14 DAY, NOW()),
 (19,'hannahlee','hannah.lee@proconnect.demo','$2y$12$ALzJ3z471uDwTiudwJSMje8khpX0NQHKPTpoXRI9AhdIzFH6B5RaS','Hannah Lee','https://i.pravatar.cc/240?u=hannahlee','https://picsum.photos/seed/hannahlee/960/240','UX Researcher · Mixed methods','Singapore','Helping teams build the right thing. I run research that actually changes roadmaps. Curiosity is my job.', NOW() - INTERVAL 12 DAY, NOW()),
 (20,'carlosm','carlos.mendes@proconnect.demo','$2y$12$ALzJ3z471uDwTiudwJSMje8khpX0NQHKPTpoXRI9AhdIzFH6B5RaS','Carlos Mendes','https://i.pravatar.cc/240?u=carlosm','https://picsum.photos/seed/carlosm/960/240','Staff Engineer · API platform','São Paulo, Brazil','Staff engineer focused on API design and developer experience. I believe great APIs are a product, not an afterthought.', NOW() - INTERVAL 10 DAY, NOW()),
 (21,'ninap','nina.petrova@proconnect.demo','$2y$12$ALzJ3z471uDwTiudwJSMje8khpX0NQHKPTpoXRI9AhdIzFH6B5RaS','Nina Petrova','https://i.pravatar.cc/240?u=ninap','https://picsum.photos/seed/ninap/960/240','Data Engineer · Streaming & Lakehouse','Amsterdam, Netherlands','Moving data reliably at scale. Kafka, Spark, dbt. I turn data swamps into data lakehouses.', NOW() - INTERVAL 8 DAY, NOW());

INSERT IGNORE INTO skills (id, user_id, name, created_at) VALUES
 (300,6,'React',NOW()),(301,6,'TypeScript',NOW()),(302,6,'Design Systems',NOW()),(303,6,'Accessibility',NOW()),
 (304,7,'Leadership',NOW()),(305,7,'Distributed Systems',NOW()),(306,7,'Hiring',NOW()),
 (307,8,'Python',NOW()),(308,8,'NLP',NOW()),(309,8,'PyTorch',NOW()),(310,8,'LLMs',NOW()),
 (311,9,'Kubernetes',NOW()),(312,9,'Observability',NOW()),(313,9,'Go',NOW()),
 (314,10,'Figma',NOW()),(315,10,'Design Systems',NOW()),(316,10,'User Research',NOW()),
 (317,11,'Go',NOW()),(318,11,'gRPC',NOW()),(319,11,'PostgreSQL',NOW()),
 (320,12,'Python',NOW()),(321,12,'Computer Vision',NOW()),(322,12,'TensorFlow',NOW()),
 (323,13,'AWS',NOW()),(324,13,'Terraform',NOW()),(325,13,'Cloud Architecture',NOW()),
 (326,14,'Swift',NOW()),(327,14,'SwiftUI',NOW()),(328,14,'iOS',NOW()),
 (329,15,'Platform Engineering',NOW()),(330,15,'Leadership',NOW()),(331,15,'Developer Experience',NOW()),
 (332,16,'Application Security',NOW()),(333,16,'Threat Modeling',NOW()),(334,16,'Penetration Testing',NOW()),
 (335,17,'TypeScript',NOW()),(336,17,'Next.js',NOW()),(337,17,'Node.js',NOW()),(338,17,'PostgreSQL',NOW()),
 (339,18,'Kubernetes',NOW()),(340,18,'Terraform',NOW()),(341,18,'Platform Engineering',NOW()),
 (342,19,'User Research',NOW()),(343,19,'Usability Testing',NOW()),(344,19,'Data Analysis',NOW()),
 (345,20,'API Design',NOW()),(346,20,'Go',NOW()),(347,20,'System Design',NOW()),
 (348,21,'Kafka',NOW()),(349,21,'Spark',NOW()),(350,21,'dbt',NOW()),(351,21,'Python',NOW());

-- ---------------------------------------------------------------------------
USE proconnect_feed;
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO posts (id, author_id, content, image_url, repost_of, created_at) VALUES
 (100,6,'After 6 months of work, we just shipped our new design system to production 🎉 40+ components, full dark mode, and WCAG AA compliance out of the box. The biggest lesson? Invest in documentation early — your future self (and your teammates) will thank you.',NULL,NULL, NOW() - INTERVAL 2 HOUR),
 (101,7,'Hot take: most "10x engineer" talk is just a failure of management. Give people clear goals, remove blockers, protect their focus time, and watch an entire team become 10x. Heroics don''t scale. Systems do.',NULL,NULL, NOW() - INTERVAL 5 HOUR),
 (102,8,'Spent the week evaluating 4 different vector databases for our RAG pipeline. Recall@10 matters far less than people think once you fix your chunking strategy. Garbage in, garbage out — even with the fanciest embeddings.',NULL,NULL, NOW() - INTERVAL 9 HOUR),
 (103,9,'Reminder: an alert that fires every day is not an alert, it''s a notification you''ve trained yourself to ignore. We cut our pager volume by 70% this quarter just by deleting noisy alerts and tightening SLOs. Less is more.',NULL,NULL, NOW() - INTERVAL 14 HOUR),
 (104,10,'Accessibility is not a feature you add at the end. It''s a constraint you design with from day one. Color contrast, focus states, keyboard navigation, screen reader labels — bake them in. Everyone benefits.',NULL,NULL, NOW() - INTERVAL 20 HOUR),
 (105,11,'Rewrote a hot path from a microservice chain into a single Go service this week. p99 latency went from 800ms to 45ms. Sometimes the best distributed system is fewer distributed systems.',NULL,NULL, NOW() - INTERVAL 26 HOUR),
 (106,12,'Our computer vision model for early diabetic retinopathy screening just passed clinical validation 🙏 Two years of work. ML in healthcare is hard, slow, and absolutely worth it. Proud of this team.',NULL,NULL, NOW() - INTERVAL 30 HOUR),
 (107,13,'Cut our AWS bill by 38% this month with zero performance impact. The secret? Nothing glamorous: right-sizing instances, killing idle resources, Graviton, and savings plans. Boring wins are still wins.',NULL,NULL, NOW() - INTERVAL 34 HOUR),
 (108,14,'SwiftUI in 2026 is genuinely a joy. Built an entire onboarding flow in an afternoon that would have taken me days in UIKit. The previews alone are worth the switch.',NULL,NULL, NOW() - INTERVAL 40 HOUR),
 (109,15,'To the engineers I''ve managed: your career is a marathon, not a sprint. Say no to the project that burns you out. The best engineers I know optimize for sustainable pace, not short-term heroics.',NULL,NULL, NOW() - INTERVAL 46 HOUR),
 (110,16,'Friendly reminder to rotate your secrets, enable MFA everywhere, and never log tokens. I just finished a threat-modeling session and 80% of real risks come from boring fundamentals, not exotic 0-days.',NULL,NULL, NOW() - INTERVAL 52 HOUR),
 (111,17,'Shipped my first open-source library to 1,000 GitHub stars ⭐ A tiny TypeScript utility I built to scratch my own itch. Lesson: solve a real problem simply, write good docs, and be patient.',NULL,NULL, NOW() - INTERVAL 58 HOUR),
 (112,18,'Our internal developer platform now lets a team go from git repo to production in under 10 minutes with golden paths. The goal was never to remove choice — it was to make the right choice the easy one.',NULL,NULL, NOW() - INTERVAL 3 DAY),
 (113,19,'Watched 8 user sessions today. Every single one struggled at the same step we thought was "obvious." This is why we test with real users. Your intuition is a hypothesis, not a fact.',NULL,NULL, NOW() - INTERVAL 3 DAY - INTERVAL 6 HOUR),
 (114,20,'A great API is empathetic. It anticipates mistakes, returns helpful errors, and is impossible to hold wrong. We just shipped a major API redesign and the #1 piece of feedback was "it just made sense." That''s the whole job.',NULL,NULL, NOW() - INTERVAL 4 DAY),
 (115,21,'Migrated our batch pipeline to streaming this quarter. Data that used to be 6 hours stale is now under 30 seconds fresh. The business impact of fresh data is consistently underestimated.',NULL,NULL, NOW() - INTERVAL 4 DAY - INTERVAL 8 HOUR),
 (116,6,'Quick tip: useMemo and useCallback are not free. I see so many codebases over-optimizing with them and adding complexity for zero measurable gain. Measure first. Premature optimization is alive and well in React.',NULL,NULL, NOW() - INTERVAL 5 DAY),
 (117,7,'We''re hiring 2 senior backend engineers (Go/Rust) for our platform team. Remote-friendly, great people, real ownership. DM me if you''re curious — happy to share what we''re building.',NULL,NULL, NOW() - INTERVAL 5 DAY - INTERVAL 4 HOUR),
 (118,8,'Reading "Designing Data-Intensive Applications" for the third time and still learning. If you work with data and haven''t read it, that''s your weekend sorted. A genuine classic.',NULL,NULL, NOW() - INTERVAL 6 DAY),
 (119,9,'Postmortem culture tip: the question is never "who caused this," it''s "what in our system allowed this to happen." Blameless postmortems turned our worst outage into our best learning quarter.',NULL,NULL, NOW() - INTERVAL 6 DAY - INTERVAL 5 HOUR),
 (120,11,'gRPC vs REST is the wrong question. The right question is: what are your latency, tooling, and team-familiarity constraints? Use the boring tool your team can operate at 3am.',NULL,NULL, NOW() - INTERVAL 7 DAY),
 (121,13,'Multi-cloud is a tax most companies pay for a problem they don''t have. Pick one cloud, go deep, move fast. Revisit when you''re actually big enough to need the leverage.',NULL,NULL, NOW() - INTERVAL 7 DAY - INTERVAL 7 HOUR),
 (122,10,'Design crit is a gift, not a judgment. The teams with the best products are the ones where it''s safe to share work at 30% done. Psychological safety is a design tool.',NULL,NULL, NOW() - INTERVAL 8 DAY),
 (123,15,'Promotion season reminder for managers: the work happened all year. If your report''s impact is a surprise to leadership in calibration, that''s a you problem, not a them problem. Advocate early and often.',NULL,NULL, NOW() - INTERVAL 8 DAY - INTERVAL 6 HOUR),
 (124,12,'MLOps reality check: 90% of "AI projects" fail not because of the model, but because of data pipelines, monitoring, and deployment. The model is the easy part. The plumbing is the job.',NULL,NULL, NOW() - INTERVAL 9 DAY),
 (125,17,'TIL you can ship a surprising amount of product as one person with TypeScript, Next.js, and a managed Postgres. The modern stack is a superpower for small teams. Constraints breed focus.',NULL,NULL, NOW() - INTERVAL 9 DAY - INTERVAL 3 HOUR),
 (126,18,'YAML is the assembly language of the cloud and I''m only half joking. Spent the morning debugging an indentation bug that took down a deploy. Invest in validation and good tooling, please.',NULL,NULL, NOW() - INTERVAL 10 DAY),
 (127,20,'Versioning your API is a promise to your users. Break it carelessly and you break trust. We just deprecated v1 after 18 months of overlap and clear comms. Do right by the people who built on you.',NULL,NULL, NOW() - INTERVAL 10 DAY - INTERVAL 5 HOUR),
 (128,19,'"We don''t have time for research" usually means "we have time to build the wrong thing twice." A week of research can save a quarter of engineering. Every time.',NULL,NULL, NOW() - INTERVAL 11 DAY),
 (129,21,'Data quality is a feature. Nobody trusts a dashboard that''s wrong once. We added contract tests to our pipelines and stakeholder trust went up more than any new chart ever did.',NULL,NULL, NOW() - INTERVAL 11 DAY - INTERVAL 8 HOUR),
 (130,14,'Performance tip for iOS: lazy-load everything you can, profile with Instruments before you guess, and remember that the fastest code is the code that never runs. Users feel every dropped frame.',NULL,NULL, NOW() - INTERVAL 12 DAY),
 (131,16,'Ran a CTF workshop for junior engineers this week. Watching the "aha" moment when someone exploits their first SQL injection — then immediately learns to prevent it — never gets old. Teach security by breaking things safely.',NULL,NULL, NOW() - INTERVAL 12 DAY - INTERVAL 4 HOUR),
 (132,7,'Career advice nobody asked for: the skill that compounds the most is clear writing. Every promotion, every design doc, every hard decision — it all runs on your ability to communicate clearly. Practice it.',NULL,NULL, NOW() - INTERVAL 13 DAY),
 (133,6,'Reposting for the new folks: your first version does not need to be perfect. It needs to exist. Ship it, learn, iterate. Perfectionism is just procrastination in a nicer outfit.',NULL,NULL, NOW() - INTERVAL 13 DAY - INTERVAL 6 HOUR);

INSERT IGNORE INTO reactions (post_id, user_id, type, created_at) VALUES
 (100,7,'like',NOW()),(100,10,'like',NOW()),(100,17,'like',NOW()),(100,2,'like',NOW()),(100,15,'like',NOW()),
 (101,9,'like',NOW()),(101,15,'like',NOW()),(101,11,'like',NOW()),(101,20,'like',NOW()),
 (102,11,'like',NOW()),(102,21,'like',NOW()),(102,12,'like',NOW()),
 (103,7,'like',NOW()),(103,13,'like',NOW()),(103,18,'like',NOW()),(103,15,'like',NOW()),
 (104,6,'like',NOW()),(104,19,'like',NOW()),(104,17,'like',NOW()),
 (105,9,'like',NOW()),(105,20,'like',NOW()),(105,18,'like',NOW()),(105,13,'like',NOW()),
 (106,8,'like',NOW()),(106,15,'like',NOW()),(106,19,'like',NOW()),(106,2,'like',NOW()),
 (107,9,'like',NOW()),(107,18,'like',NOW()),(107,21,'like',NOW()),
 (108,17,'like',NOW()),(108,6,'like',NOW()),
 (109,7,'like',NOW()),(109,10,'like',NOW()),(109,16,'like',NOW()),(109,19,'like',NOW()),(109,20,'like',NOW()),
 (110,9,'like',NOW()),(110,13,'like',NOW()),(110,18,'like',NOW()),
 (111,6,'like',NOW()),(111,20,'like',NOW()),(111,17,'like',NOW()),(111,11,'like',NOW()),
 (112,9,'like',NOW()),(112,15,'like',NOW()),(112,13,'like',NOW()),
 (113,10,'like',NOW()),(113,15,'like',NOW()),
 (114,11,'like',NOW()),(114,17,'like',NOW()),(114,7,'like',NOW()),
 (115,8,'like',NOW()),(115,9,'like',NOW()),(115,18,'like',NOW()),
 (116,17,'like',NOW()),(116,10,'like',NOW()),
 (117,11,'like',NOW()),(117,20,'like',NOW()),(117,9,'like',NOW()),
 (119,9,'like',NOW()),(119,13,'like',NOW()),(119,7,'like',NOW()),
 (124,8,'like',NOW()),(124,21,'like',NOW()),(124,12,'like',NOW()),
 (132,6,'like',NOW()),(132,10,'like',NOW()),(132,15,'like',NOW()),(132,17,'like',NOW()),(132,19,'like',NOW());

INSERT IGNORE INTO comments (id, post_id, author_id, body, created_at) VALUES
 (200,100,7,'Congrats! Documentation-first is so underrated. How did you handle versioning across teams?', NOW() - INTERVAL 1 HOUR),
 (201,100,10,'This is fantastic work. The dark mode support out of the box is chef''s kiss 👌', NOW() - INTERVAL 30 MINUTE),
 (202,101,15,'100%. The best thing a manager can do is get out of the way and remove blockers.', NOW() - INTERVAL 4 HOUR),
 (203,101,9,'Systems over heroics — putting this on the team wall.', NOW() - INTERVAL 3 HOUR),
 (204,102,11,'Chunking strategy is everything. People skip straight to the model and wonder why recall is bad.', NOW() - INTERVAL 8 HOUR),
 (205,103,13,'70% pager reduction is huge. Did you measure impact on on-call satisfaction too?', NOW() - INTERVAL 12 HOUR),
 (206,104,6,'Yes! Designing with constraints from day one is the way. Sharing with my team.', NOW() - INTERVAL 18 HOUR),
 (207,105,20,'Fewer distributed systems is an underrated architecture pattern. Love this.', NOW() - INTERVAL 24 HOUR),
 (208,106,15,'Incredible milestone. Healthcare ML is a marathon — congratulations to the whole team.', NOW() - INTERVAL 28 HOUR),
 (209,107,18,'Graviton + savings plans is the easiest cost win nobody takes. Nice.', NOW() - INTERVAL 32 HOUR),
 (210,109,19,'Needed to read this today. Sustainable pace really is the long game.', NOW() - INTERVAL 44 HOUR),
 (211,111,17,'Congrats on 1k stars! Good docs really are the difference. Well deserved.', NOW() - INTERVAL 56 HOUR),
 (212,114,11,'"Impossible to hold wrong" is the perfect way to put it. Stealing that.', NOW() - INTERVAL 3 DAY),
 (213,117,11,'Sounds like a great team — just sent you a DM!', NOW() - INTERVAL 5 DAY),
 (214,124,8,'The plumbing is the job. So true it hurts. Monitoring is where projects quietly die.', NOW() - INTERVAL 9 DAY),
 (215,132,15,'Clear writing is the highest-leverage skill, full stop. Great reminder.', NOW() - INTERVAL 13 DAY);

-- ---------------------------------------------------------------------------
USE proconnect_connection;
-- pair_lo / pair_hi are STORED GENERATED — do NOT insert them. INSERT IGNORE
-- relies on the undirected unique pair index for idempotency.
-- Connect demo accounts (1=demo, 2=duyet, 3=long) to the new pros so their
-- feeds light up, plus a dense graph among the new pros.
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO connections (requester_id, addressee_id, status, created_at, updated_at) VALUES
 (2,6,'accepted',NOW(),NOW()),(2,7,'accepted',NOW(),NOW()),(2,8,'accepted',NOW(),NOW()),(2,9,'accepted',NOW(),NOW()),
 (2,11,'accepted',NOW(),NOW()),(2,13,'accepted',NOW(),NOW()),(2,15,'accepted',NOW(),NOW()),(2,17,'accepted',NOW(),NOW()),
 (2,20,'accepted',NOW(),NOW()),(2,21,'accepted',NOW(),NOW()),
 (1,6,'accepted',NOW(),NOW()),(1,10,'accepted',NOW(),NOW()),(1,12,'accepted',NOW(),NOW()),(1,16,'accepted',NOW(),NOW()),(1,19,'accepted',NOW(),NOW()),
 (3,7,'accepted',NOW(),NOW()),(3,9,'accepted',NOW(),NOW()),(3,14,'accepted',NOW(),NOW()),(3,18,'accepted',NOW(),NOW()),
 (6,7,'accepted',NOW(),NOW()),(6,10,'accepted',NOW(),NOW()),(6,17,'accepted',NOW(),NOW()),
 (7,9,'accepted',NOW(),NOW()),(7,15,'accepted',NOW(),NOW()),(7,11,'accepted',NOW(),NOW()),
 (8,11,'accepted',NOW(),NOW()),(8,21,'accepted',NOW(),NOW()),(8,12,'accepted',NOW(),NOW()),
 (9,13,'accepted',NOW(),NOW()),(9,18,'accepted',NOW(),NOW()),
 (10,19,'accepted',NOW(),NOW()),(10,6,'accepted',NOW(),NOW()),
 (11,20,'accepted',NOW(),NOW()),(13,18,'accepted',NOW(),NOW()),(15,20,'accepted',NOW(),NOW()),
 (12,21,'accepted',NOW(),NOW()),(14,17,'accepted',NOW(),NOW()),(16,9,'accepted',NOW(),NOW()),
 (4,6,'pending',NOW(),NOW()),(5,8,'pending',NOW(),NOW()),(18,2,'pending',NOW(),NOW()),(19,2,'pending',NOW(),NOW());
