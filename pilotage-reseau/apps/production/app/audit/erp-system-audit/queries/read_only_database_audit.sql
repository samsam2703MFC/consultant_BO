-- TYLKO READ-ONLY. Najpierw potwierdzić bazę testową/deweloperską.
SELECT DATABASE(), VERSION();
SELECT table_schema, COUNT(*) AS table_count FROM information_schema.tables
WHERE table_schema = DATABASE() GROUP BY table_schema;
SELECT table_name, engine, table_rows, data_length, index_length
FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name;
SELECT table_name, column_name, data_type, column_type, is_nullable, column_default
FROM information_schema.columns WHERE table_schema = DATABASE()
ORDER BY table_name, ordinal_position;
SELECT table_name, constraint_name, constraint_type
FROM information_schema.table_constraints WHERE table_schema = DATABASE()
ORDER BY table_name, constraint_type;
SELECT table_name, index_name, non_unique, seq_in_index, column_name
FROM information_schema.statistics WHERE table_schema = DATABASE()
ORDER BY table_name, index_name, seq_in_index;

